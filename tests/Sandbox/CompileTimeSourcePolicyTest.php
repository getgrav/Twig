<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Tests\Sandbox;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Twig\BlockChain;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\LoaderInterface;
use Twig\Sandbox\CompileTimeSourcePolicyInterface;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;
use Twig\Template;

/**
 * GRAV FORK: compile-time source sandboxing, see CompileTimeSourcePolicyInterface.
 *
 * The policy used here sandboxes "user/" templates, trusts "trusted/" templates
 * and leaves every other name untrusted but not sandboxed (the case of a string
 * template that may later be rendered inside a sandboxed include).
 *
 * Grav core pins the same behaviour against its own policy in
 * tests/unit/Grav/Common/Twig/Sandbox/SourceSandboxCompilationTest.php.
 *
 * Legacy: SourcePolicyInterface, the sandbox tag and include(sandboxed) are all
 * deprecated upstream.
 *
 * @group legacy
 */
#[Group('legacy')]
class CompileTimeSourcePolicyTest extends TestCase
{
    private const TEMPLATES = [
        'trusted/print' => '<b>{{ probe }}</b>',
        'trusted/attr' => "{{ probe.secret }}|{{ probe.getSecret() }}|{{ map.key }}|{{ map['key'] }}",
        'trusted/filters' => '{{ items|map(i => i * 2)|join(",") }}|{{ items has some i => i > 2 ? "y" : "n" }}|{{ items has every i => i > 0 ? "y" : "n" }}|{{ "<i>"|raw }}',
        'trusted/destructuring' => '{% do {secret} = probe %}{{ secret }}',
        'trusted/base' => '<main>{% block body %}{% endblock %}</main>',
        'trusted/child' => "{% extends 'trusted/base' %}{% block body %}[{{ probe }}]{% endblock %}",
        'trusted/embed' => "{% embed 'trusted/base' %}{% block body %}({{ probe }}){% endblock %}{% endembed %}",
        'trusted/macros' => '{% macro show(v) %}<{{ v }}>{% endmacro %}',
        'trusted/sandbox_tag' => "{% sandbox %}{% include 'trusted/print' %}{% endsandbox %}",
        'trusted/sandboxed_include' => "{{ include('trusted/child', sandboxed = true) }}",
        'trusted/sandboxed_include_string' => '{{ include(template_from_string(code), sandboxed = true) }}',
        'trusted/sandboxed_include_wrapper' => '{{ include(wrapper, sandboxed = true) }}',
        'trusted/sandboxed_include_arrow' => "{{ include('trusted/apply', {f: (x) => probe.secret ~ x, items: ['!']}, sandboxed = true) }}",
        'trusted/sandboxed_include_arrow_string' => "{{ include('trusted/apply', {f: (x) => probe ~ x, items: ['!']}, sandboxed = true) }}",
        'trusted/apply' => '{{ items|map(f)|join }}',
        'trusted/lines' => "<p>\n{{ probe.secret }}</p>",
        'trusted/dynamic_child' => '{% extends probe.parent %}{% block body %}[{{ name }}]{% endblock %}',
        'trusted/parent_call' => "{% extends 'trusted/base' %}{% block body %}<{{ probe }}>{{ parent() }}{% endblock %}",
        'trusted/macro_base' => '{% macro show(v) %}<{{ v }}>{% endmacro %}',
        'trusted/macro_child' => "{% extends 'trusted/macro_base' %}",
        'trusted/sandboxed_macros' => "{{ include('trusted/call_macro', sandboxed = true) }}",
        'trusted/call_macro' => '{% import wrapper as m %}{{ m.show(probe) }}',
        'trusted/sandboxed_block' => "{{ include('trusted/call_block', sandboxed = true) }}",
        'trusted/call_block' => "{{ block('body', wrapper) }}",
        'trusted/sandboxed_extends' => "{{ include('trusted/extends_wrapper', sandboxed = true) }}",
        'trusted/extends_wrapper' => '{% extends wrapper %}',
        'trusted/sandbox_tag_wrapper' => '{% sandbox %}{% include wrapper %}{% endsandbox %}',
        'user/print' => '{{ probe }}',
        'user/attr' => '{{ probe.secret }}',
        'user/raw' => '{{ "<i>"|raw }}',
        'user/include_trusted' => "{% include 'trusted/print' %}|{{ name|upper }}",
        'plain/print' => '{{ probe }}',
    ];

    public function testWithoutTheOptInNothingChanges(): void
    {
        $env = $this->env(false);

        $this->assertStringNotContainsString('_sourced', $env->getTemplateClass('trusted/print'));
        $this->assertStringNotContainsString('_sandboxed', $env->getTemplateClass('trusted/print'));

        $code = $this->compile($env, 'trusted/print');
        $this->assertStringContainsString('ensureToStringAllowed', $code);
        $this->assertStringContainsString('$this->sandbox->checkSecurity(', $code);
        $this->assertStringNotContainsString('was loaded as a trusted template', $code);
    }

    public function testTrustedTemplateCompilesWithoutAnyInstrumentation(): void
    {
        $env = $this->env();

        foreach (['trusted/print', 'trusted/attr', 'trusted/destructuring', 'trusted/child', 'trusted/embed'] as $name) {
            $code = $this->compile($env, $name);
            $this->assertStringNotContainsString('ensureToStringAllowed', $code, $name);
            $this->assertStringNotContainsString('checkSecurity', $code, $name);
            $this->assertStringNotContainsString('->isSandboxed($this->source)', $code, $name);
            $this->assertStringNotContainsString(', true, ', $this->getAttributeCalls($code), $name);
            // only the guard and the checker it reads remain
            $this->assertStringContainsString('$this->sandbox = $env->getExtension(SandboxExtension::class)->getChecker();', $code, $name);
            $this->assertStringContainsString('if ($this->sandbox->isSandboxed()) {', $code, $name);
        }

        // array access takes the same inline path as without a sandbox
        $this->assertStringNotContainsString('CoreExtension::ARRAY_LIKE_CLASSES', $this->compile($env, 'trusted/attr'));
        $this->assertStringNotContainsString('CoreExtension::ARRAY_LIKE_CLASSES', $this->compile($this->env(false, false), 'trusted/attr'));
        $this->assertStringContainsString('CoreExtension::ARRAY_LIKE_CLASSES', $this->compile($this->env(false), 'trusted/attr'));
    }

    public function testTrustedTemplateCompilesLikeTheSameTemplateWithoutASandbox(): void
    {
        // the class name, the checker, the guard's two methods, and the debug line map it shifts
        $normalize = static fn (string $code): string => preg_replace([
            '/__TwigTemplate_\w+/',
            '/\n\s*\$this->sandbox = [^\n]*/',
            '/\n\s*public function ensureSecurityCheckedOrHandOver\(\).*?\n\s*public function ensureSecurityChecked\(\): void\n\s*\{\n.*?\n    \}\n/s',
            '/return array \(.*?\);/',
        ], ['CLASS', '', "\n", 'return DEBUG;'], $code);

        foreach (['trusted/attr', 'trusted/destructuring'] as $name) {
            $this->assertSame(
                $normalize($this->compile($this->env(false, false), $name)),
                $normalize($this->compile($this->env(), $name)),
                $name
            );
        }
    }

    public function testTrustedTemplatePassesAFixedSandboxStateToCallablesAndOperators(): void
    {
        $code = $this->compile($this->env(), 'trusted/filters');

        $this->assertStringContainsString('CoreExtension::map($this->env, false, ', $code);
        $this->assertSame(2, substr_count($code, '; }, false);'), 'has some and has every');

        $code = $this->compile($this->env(false), 'trusted/filters');
        $this->assertSame(3, substr_count($code, '->getChecker()->isSandboxed($this->source)'));
    }

    public function testTrustedTemplateRendersTheSameOutput(): void
    {
        $context = ['probe' => new CompileTimeSourcePolicyProbe(), 'map' => ['key' => 'value'], 'items' => [1, 2, 3], 'name' => 'twig'];

        foreach (['trusted/print', 'trusted/attr', 'trusted/filters', 'trusted/destructuring', 'trusted/child', 'trusted/embed'] as $name) {
            $this->assertSame($this->env(false)->render($name, $context), $this->env()->render($name, $context), $name);
        }

        $this->assertSame('<b>probe</b>', $this->env()->render('trusted/print', $context));
    }

    public function testTrustedTemplateNeverConsultsTheSourcePolicyWhenRendering(): void
    {
        $context = ['probe' => new CompileTimeSourcePolicyProbe(), 'map' => ['key' => 'value'], 'items' => [1, 2, 3]];

        foreach ([[true, 0], [false, null]] as [$optIn, $expected]) {
            $policy = new CompileTimeSourcePolicyTestPolicy();
            $env = $this->env($optIn, true, $policy);
            foreach (['trusted/child', 'trusted/attr', 'trusted/destructuring'] as $name) {
                $env->render($name, $context);
            }
            $policy->calls = 0;
            foreach (['trusted/child', 'trusted/attr', 'trusted/destructuring'] as $name) {
                $env->render($name, $context);
            }

            if (null === $expected) {
                $this->assertGreaterThan(10, $policy->calls);
            } else {
                $this->assertSame($expected, $policy->calls);
            }
        }
    }

    public function testSandboxedSourceKeepsEveryCheck(): void
    {
        $env = $this->env();
        $this->assertStringContainsString('ensureToStringAllowed', $this->compile($env, 'user/print'));

        foreach ([
            ['user/print', SecurityNotAllowedMethodError::class],
            ['user/attr', SecurityNotAllowedPropertyError::class],
            ['user/raw', SecurityNotAllowedFilterError::class],
        ] as [$name, $error]) {
            try {
                $env->render($name, ['probe' => new CompileTimeSourcePolicyProbe()]);
                $this->fail($name.' should have been blocked');
            } catch (SecurityError $e) {
                $this->assertInstanceOf($error, $e, $name);
            }
        }
    }

    public function testUntrustedSourceKeepsItsChecksButIsNotSandboxed(): void
    {
        $env = $this->env();

        $this->assertStringContainsString('ensureToStringAllowed', $this->compile($env, 'plain/print'));
        $this->assertSame('probe', $env->render('plain/print', ['probe' => new CompileTimeSourcePolicyProbe()]));
    }

    public function testSandboxedSourceIncludingATrustedTemplate(): void
    {
        $context = ['probe' => new CompileTimeSourcePolicyProbe(), 'name' => 'twig'];

        $this->assertSame('<b>probe</b>|TWIG', $this->env(false)->render('user/include_trusted', $context));
        $this->assertSame('<b>probe</b>|TWIG', $this->env()->render('user/include_trusted', $context));
    }

    public function testClassNamesDependOnTheMode(): void
    {
        $env = $this->env();
        $legacy = $this->env(false);

        $this->assertStringEndsWith('_sourced', $env->getTemplateClass('trusted/print'));
        $this->assertStringEndsWith('_sourced___2', $env->getTemplateClass('trusted/print', 2));
        $this->assertMatchesRegularExpression('/^__TwigTemplate_[0-9a-f]+$/', $legacy->getTemplateClass('trusted/print'));

        $checker = $env->getExtension(SandboxExtension::class)->getChecker();
        $checker->setSandboxed(true);
        try {
            $this->assertStringEndsWith('_sandboxed', $env->getTemplateClass('trusted/print'));
            $this->assertStringContainsString('ensureToStringAllowed', $this->compile($env, 'trusted/print'));
        } finally {
            $checker->setSandboxed(false);
        }
    }

    public function testGloballySandboxedExtensionTrustsNothing(): void
    {
        $env = $this->env(true, true, null, true);

        $this->assertStringEndsWith('_sandboxed', $env->getTemplateClass('trusted/print'));
        $this->assertStringContainsString('ensureToStringAllowed', $this->compile($env, 'trusted/print'));

        $this->expectException(SecurityNotAllowedMethodError::class);
        $env->render('trusted/print', ['probe' => new CompileTimeSourcePolicyProbe()]);
    }

    public function testSandboxTagKeepsTheTemplateChecksAndChecksTheInclude(): void
    {
        $env = $this->env();
        $this->assertStringContainsString('checkSecurity', $this->compile($env, 'trusted/sandbox_tag'));

        $this->expectException(SecurityNotAllowedMethodError::class);
        $env->render('trusted/sandbox_tag', ['probe' => new CompileTimeSourcePolicyProbe()]);
    }

    public function testSandboxedIncludeChecksTheTemplateAndItsParent(): void
    {
        $env = $this->env();
        $this->assertSame('<main>[ok]</main>', $env->render('trusted/sandboxed_include', ['probe' => 'ok']));

        $this->expectException(SecurityNotAllowedMethodError::class);
        $env->render('trusted/sandboxed_include', ['probe' => new CompileTimeSourcePolicyProbe()]);
    }

    public function testSandboxedRenderDoesNotLeakIntoLaterTrustedRenders(): void
    {
        $env = $this->env();
        $context = ['probe' => new CompileTimeSourcePolicyProbe()];

        $this->assertSame('<main>[probe]</main>', $env->render('trusted/child', $context));
        try {
            $env->render('trusted/sandboxed_include', $context);
            $this->fail('The sandboxed include should have been blocked');
        } catch (SecurityNotAllowedMethodError $e) {
        }
        $this->assertSame('<main>[probe]</main>', $env->render('trusted/child', $context));
    }

    public function testSandboxedIncludeOfAnUntrustedStringTemplateIsChecked(): void
    {
        $env = $this->env();
        $env->addExtension(new StringLoaderExtension());

        $this->assertSame('OK', $env->render('trusted/sandboxed_include_string', ['code' => '{{ "ok"|upper }}']));

        $this->expectException(SecurityNotAllowedMethodError::class);
        $env->render('trusted/sandboxed_include_string', ['code' => '{{ probe }}', 'probe' => new CompileTimeSourcePolicyProbe()]);
    }

    /**
     * A closure is the one piece of a trusted template that can run inside a
     * sandboxed render without going through the guard, so arrow function
     * bodies keep their per-value checks, as upstream compiles them.
     */
    public function testArrowFunctionFromATrustedTemplateIsCheckedInsideTheSandbox(): void
    {
        $code = $this->compile($this->env(), 'trusted/sandboxed_include_arrow');
        $this->assertStringContainsString('CoreExtension::getAttribute($this->env, $this->source, ($context["probe"] ?? null), "secret", [], "any", false, false, true, 1)', $code);
        $this->assertStringContainsString('ensureToStringAllowed(($context["probe"] ?? null)', $this->compile($this->env(), 'trusted/sandboxed_include_arrow_string'));

        foreach ([true, false] as $optIn) {
            foreach (['trusted/sandboxed_include_arrow' => SecurityNotAllowedPropertyError::class, 'trusted/sandboxed_include_arrow_string' => SecurityNotAllowedMethodError::class] as $name => $error) {
                try {
                    $this->env($optIn)->render($name, ['probe' => new CompileTimeSourcePolicyProbe()]);
                    $this->fail($name.' should have been blocked');
                } catch (SecurityError $e) {
                    $this->assertInstanceOf($error, $e, $name);
                }
            }
        }

        $this->assertSame('probe!', $this->env()->render('trusted/sandboxed_include_arrow_string', ['probe' => 'probe']));
    }

    /**
     * A trusted template loaded before the sandbox turns on (here as a TemplateWrapper
     * handed to a sandboxed render) runs as its fully checked variant, so it renders
     * and fails exactly as upstream Twig, where the same instance carries the checks.
     */
    public function testPreloadedTrustedTemplateRendersCheckedInsideTheSandbox(): void
    {
        foreach (['trusted/sandboxed_include_wrapper', 'trusted/sandbox_tag_wrapper'] as $entry) {
            foreach (['trusted/child', 'trusted/print', 'trusted/embed', 'trusted/parent_call'] as $name) {
                $this->assertSameAsUpstream("$entry $name", static fn (Environment $env) => $env->render($entry, ['wrapper' => $env->load($name), 'probe' => 'ok']));
                $this->assertSameAsUpstream("$entry $name", static fn (Environment $env) => $env->render($entry, ['wrapper' => $env->load($name), 'probe' => new CompileTimeSourcePolicyProbe()]), SecurityNotAllowedMethodError::class);
            }
        }

        $this->assertSameAsUpstream('attr', static fn (Environment $env) => $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $env->load('trusted/attr'), 'probe' => new CompileTimeSourcePolicyProbe(), 'map' => []]), SecurityNotAllowedPropertyError::class);
        $this->assertSameAsUpstream('filters', static fn (Environment $env) => $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $env->load('trusted/filters'), 'items' => [1, 2, 3]]), SecurityNotAllowedFilterError::class);

        $env = $this->env();
        $this->assertSame('<main>[ok]</main>', $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $env->load('trusted/child'), 'probe' => 'ok']));
    }

    public function testPreloadedTrustedTemplateKeepsItsDisallowedFunctionBlocked(): void
    {
        foreach ([true, false] as $optIn) {
            $env = $this->env($optIn);
            $env->addFunction(new \Twig\TwigFunction('forbidden', static fn () => 'forbidden'));
            $env->setLoader(new CompileTimeSourcePolicyLoader(self::TEMPLATES + ['trusted/forbidden' => '{{ forbidden() }}']));
            $wrapper = $env->load('trusted/forbidden');
            $this->assertSame('forbidden', $wrapper->render());

            try {
                $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $wrapper]);
                $this->fail('forbidden() should have been blocked');
            } catch (SecurityNotAllowedFunctionError $e) {
                $this->assertSame('forbidden', $e->getFunctionName());
            }
        }
    }

    public function testPreloadedTrustedTemplateReportsTheRightLine(): void
    {
        $env = $this->env();

        try {
            $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $env->load('trusted/lines'), 'probe' => new CompileTimeSourcePolicyProbe()]);
            $this->fail('The property should have been blocked');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertSame('trusted/lines', $e->getSourceContext()->getName());
            $this->assertSame(2, $e->getTemplateLine());
        }
    }

    public function testPreloadedTrustedBlocksRenderCheckedInsideTheSandbox(): void
    {
        // block(name, template): the block comes from the trusted template's own blocks
        $this->assertSameAsUpstream('block()', static fn (Environment $env) => $env->render('trusted/sandboxed_block', ['wrapper' => $env->load('trusted/child'), 'probe' => 'ok']));
        $this->assertSameAsUpstream('block()', static fn (Environment $env) => $env->render('trusted/sandboxed_block', ['wrapper' => $env->load('trusted/child'), 'probe' => new CompileTimeSourcePolicyProbe()]), SecurityNotAllowedMethodError::class);

        // blocks handed over in $blocks (a block chain built from the trusted template)
        foreach (['ok' => null, 'probe' => SecurityNotAllowedMethodError::class] as $probe => $error) {
            $this->assertSameAsUpstream('chain', function (Environment $env) use ($probe) {
                $chain = new BlockChain($env, [$env->load('trusted/parent_call')]);

                return $this->sandboxed($env, static fn () => $chain->renderBlock('body', ['probe' => 'ok' === $probe ? 'ok' : new CompileTimeSourcePolicyProbe()]));
            }, $error);
        }

        // the wrapper's own block API
        $this->assertSameAsUpstream('renderBlock', fn (Environment $env) => $this->sandboxed($env, static fn () => $env->load('trusted/parent_call')->renderBlock('body', ['probe' => new CompileTimeSourcePolicyProbe()])), SecurityNotAllowedMethodError::class);
        $this->assertSameAsUpstream('renderBlock', fn (Environment $env) => $this->sandboxed($env, static fn () => $env->load('trusted/parent_call')->renderBlock('body', ['probe' => 'ok'])));
    }

    public function testPreloadedTrustedParentIsResolvedChecked(): void
    {
        $env = $this->env();
        $child = $env->load('trusted/child')->unwrap($env);

        // resolving the parent inside the sandbox hands over to the checked child
        $parent = $this->sandboxed($env, static fn () => $child->getParent([]));
        $this->assertStringEndsWith('_sandboxed', $parent::class);
        $this->assertSame('trusted/base', $parent->getTemplateName());

        // a parent resolved earlier is returned as is, and hands over when it runs
        $env = $this->env();
        $child = $env->load('trusted/child')->unwrap($env);
        $this->assertStringEndsWith('_sourced', $child->getParent([])::class);
        $parent = $this->sandboxed($env, static fn () => $child->getParent([]));
        $this->assertStringEndsWith('_sourced', $parent::class);
        $this->assertSame('<main></main>', $this->sandboxed($env, static fn () => $parent->render([])));
        $this->assertInstanceOf(Template::class, $this->sandboxed($env, static fn () => $parent->ensureSecurityCheckedOrHandOver()));

        // a dynamic parent expression is evaluated with the checks
        $context = ['probe' => new CompileTimeSourcePolicyProbe(), 'name' => 'n'];
        $this->assertSameAsUpstream('getParent', fn (Environment $env) => $this->sandboxed($env, static fn () => $env->load('trusted/dynamic_child')->unwrap($env)->getParent($context)->getTemplateName()), SecurityNotAllowedPropertyError::class);
        $this->assertSameAsUpstream('hasBlock', fn (Environment $env) => $this->sandboxed($env, static fn () => $env->load('trusted/dynamic_child')->hasBlock('nope', $context)), SecurityNotAllowedPropertyError::class);
        $this->assertSameAsUpstream('extends', static fn (Environment $env) => $env->render('trusted/sandboxed_extends', ['wrapper' => $env->load('trusted/dynamic_child')] + $context), SecurityNotAllowedPropertyError::class);
        $this->assertSameAsUpstream('extends', static fn (Environment $env) => $env->render('trusted/sandboxed_extends', ['wrapper' => $env->load('trusted/child'), 'probe' => 'ok']));
    }

    public function testPreloadedTrustedMacrosRunCheckedInsideTheSandbox(): void
    {
        foreach (['trusted/macros', 'trusted/macro_child'] as $name) {
            $call = fn (Environment $env, $value) => $this->sandboxed($env, static fn () => (string) $env->load($name)->unwrap($env)->getMacroNamespace()->call('show', [$value], [], 1, new Source('', 'test')));

            $this->assertSameAsUpstream($name, static fn (Environment $env) => $call($env, 'x'));
            $this->assertSameAsUpstream($name, static fn (Environment $env) => $call($env, new CompileTimeSourcePolicyProbe()), SecurityNotAllowedMethodError::class);

            // imported inside a sandboxed include
            $this->assertSameAsUpstream($name, static fn (Environment $env) => $env->render('trusted/sandboxed_macros', ['wrapper' => $env->load($name), 'probe' => 'x']));
            $this->assertSameAsUpstream($name, static fn (Environment $env) => $env->render('trusted/sandboxed_macros', ['wrapper' => $env->load($name), 'probe' => new CompileTimeSourcePolicyProbe()]), SecurityNotAllowedMethodError::class);
        }

        $env = $this->env();
        $this->assertSame('<x>', $env->render('trusted/sandboxed_macros', ['wrapper' => $env->load('trusted/macro_child'), 'probe' => 'x']));
    }

    public function testNoDelegationWithTheSandboxOff(): void
    {
        $env = $this->env();
        $context = ['probe' => new CompileTimeSourcePolicyProbe(), 'map' => ['key' => 'value'], 'items' => [1, 2, 3], 'name' => 'twig'];
        $names = ['trusted/print', 'trusted/attr', 'trusted/filters', 'trusted/child', 'trusted/embed', 'trusted/parent_call', 'trusted/macro_child', 'trusted/base'];

        foreach ($names as $name) {
            $template = $env->load($name);
            $this->assertNull($template->unwrap($env)->ensureSecurityCheckedOrHandOver(), $name);
            $template->render($context);
        }
        $env->load('trusted/parent_call')->renderBlock('body', $context);
        (new BlockChain($env, [$env->load('trusted/parent_call')]))->renderBlock('body', $context);
        $env->load('trusted/macro_child')->unwrap($env)->getMacroNamespace()->call('show', ['x'], [], 1, new Source('', 'test'));
        $env->load('trusted/child')->unwrap($env)->getParent($context);

        // no checked variant was ever loaded
        $checker = $env->getExtension(SandboxExtension::class)->getChecker();
        $checker->setSandboxed(true);
        try {
            $sandboxedClasses = array_map(static fn ($name) => $env->getTemplateClass($name), $names);
        } finally {
            $checker->setSandboxed(false);
        }
        foreach ($sandboxedClasses as $class) {
            $this->assertStringEndsWith('_sandboxed', $class);
            $this->assertFalse(class_exists($class, false), $class);
        }
    }

    public function testCheckedVariantNeverDelegatesAndKeepsTheEmbedIndex(): void
    {
        $env = $this->env();
        $main = $env->load('trusted/embed')->unwrap($env);
        $main->render([]);
        $embedded = $env->loadTemplate($env->getTemplateClass('trusted/embed'), 'trusted/embed', 1);
        $this->assertStringEndsWith('_sourced___1', $embedded::class);

        $checked = $this->sandboxed($env, static fn () => [$main->ensureSecurityCheckedOrHandOver(), $embedded->ensureSecurityCheckedOrHandOver()]);
        $this->assertStringEndsWith('_sandboxed', $checked[0]::class);
        $this->assertStringEndsWith('_sandboxed___1', $checked[1]::class);
        $this->assertSame($checked[0], $this->sandboxed($env, static fn () => $main->ensureSecurityCheckedOrHandOver()), 'loaded once');

        $this->assertNull($this->sandboxed($env, static fn () => $checked[0]->ensureSecurityCheckedOrHandOver()));
        $this->assertNull($this->sandboxed($env, static fn () => $checked[1]->ensureSecurityCheckedOrHandOver()));
    }

    /**
     * The guard still throws for any path that does not delegate, and for a trusted
     * template that cannot be loaded again by name.
     */
    public function testBackstopStillThrows(): void
    {
        $env = $this->env();
        $template = $env->load('trusted/child')->unwrap($env);
        $template->ensureSecurityChecked();

        try {
            $this->sandboxed($env, static fn () => $template->ensureSecurityChecked());
            $this->fail('The guard should have thrown');
        } catch (SecurityError $e) {
            $this->assertStringContainsString('was loaded as a trusted template and cannot be rendered while the sandbox is enabled', $e->getMessage());
        }

        $string = $env->createTemplate('<{{ probe }}>', 'trusted/string');
        $this->assertSame('<ok>', $string->render(['probe' => 'ok']));
        $this->assertStringEndsWith('_sourced', $string->unwrap($env)::class);
        try {
            $this->sandboxed($env, static fn () => $string->unwrap($env)->ensureSecurityCheckedOrHandOver());
            $this->fail('A trusted template that cannot be loaded again should have thrown');
        } catch (SecurityError $e) {
            $this->assertStringContainsString('cannot be rendered while the sandbox is enabled', $e->getMessage());
        }

        $this->expectException(SecurityError::class);
        $this->expectExceptionMessage('was loaded as a trusted template and cannot be rendered while the sandbox is enabled');
        $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $string, 'probe' => 'ok']);
    }

    /**
     * Runs $scenario against the opted-in environment and against the same policy
     * without the opt-in (upstream behaviour), and asserts both render the same
     * output, or both fail with $error.
     *
     * @param class-string<SecurityError>|null $error
     */
    private function assertSameAsUpstream(string $message, callable $scenario, ?string $error = null): void
    {
        $results = [];
        foreach ([false, true] as $optIn) {
            try {
                $results[] = ['output', $scenario($this->env($optIn))];
            } catch (SecurityError $e) {
                $results[] = ['error', $e::class];
            }
        }

        $this->assertSame($results[0], $results[1], $message);
        $this->assertSame(null === $error ? 'output' : 'error', $results[1][0], $message.': '.json_encode($results[1]));
        if (null !== $error) {
            $this->assertSame($error, $results[1][1], $message);
        }
    }

    private function sandboxed(Environment $env, callable $callback): mixed
    {
        $checker = $env->getExtension(SandboxExtension::class)->getChecker();
        $checker->setSandboxed(true);
        try {
            return $callback();
        } finally {
            $checker->setSandboxed(false);
        }
    }

    private function env(bool $optIn = true, bool $sandbox = true, ?SourcePolicyInterface $policy = null, bool $global = false): Environment
    {
        $env = new Environment(new CompileTimeSourcePolicyLoader(self::TEMPLATES), ['cache' => false, 'autoescape' => false]);
        if ($sandbox) {
            $policy ??= new CompileTimeSourcePolicyTestPolicy();
            $env->addExtension(new SandboxExtension(new SecurityPolicy(['do', 'include', 'block', 'import', 'extends', 'embed', 'macro'], ['upper', 'join', 'map'], [], [], ['include', 'block', 'parent']), $global, $optIn ? $policy : new CompileTimeSourcePolicyLegacyPolicy($policy)));
        }

        return $env;
    }

    private function compile(Environment $env, string $name): string
    {
        return $env->compileSource($env->getLoader()->getSourceContext($name));
    }

    private function getAttributeCalls(string $code): string
    {
        preg_match_all('/CoreExtension::getAttribute\(.*$/m', $code, $m);

        return implode("\n", $m[0]);
    }
}

class CompileTimeSourcePolicyProbe
{
    public $secret = 'secret';
    public $parent = 'trusted/base';

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function __toString(): string
    {
        return 'probe';
    }
}

class CompileTimeSourcePolicyTestPolicy implements CompileTimeSourcePolicyInterface
{
    public int $calls = 0;

    public function enableSandbox(Source $source): bool
    {
        ++$this->calls;

        return str_starts_with($source->getName(), 'user/');
    }

    public function isTrusted(Source $source): bool
    {
        return str_starts_with($source->getName(), 'trusted/');
    }
}

/**
 * The same decisions without the opt-in.
 */
class CompileTimeSourcePolicyLegacyPolicy implements SourcePolicyInterface
{
    public function __construct(
        private SourcePolicyInterface $policy,
    ) {
    }

    public function enableSandbox(Source $source): bool
    {
        return $this->policy->enableSandbox($source);
    }
}

/**
 * Salts the cache key so each environment compiles its own classes instead of
 * reusing ones another test declared in this process.
 */
class CompileTimeSourcePolicyLoader implements LoaderInterface
{
    private ArrayLoader $loader;
    private string $salt;

    public function __construct(array $templates)
    {
        $this->loader = new ArrayLoader($templates);
        $this->salt = bin2hex(random_bytes(8));
    }

    public function getSourceContext(string $name): Source
    {
        return $this->loader->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        return $this->salt.':'.$this->loader->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->loader->isFresh($name, $time);
    }

    public function exists(string $name): bool
    {
        return $this->loader->exists($name);
    }
}
