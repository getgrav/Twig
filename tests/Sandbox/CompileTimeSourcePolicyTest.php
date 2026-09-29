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
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\LoaderInterface;
use Twig\Sandbox\CompileTimeSourcePolicyInterface;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;

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

        foreach (['trusted/print', 'trusted/attr', 'trusted/filters', 'trusted/destructuring', 'trusted/child', 'trusted/embed'] as $name) {
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
        // the class name, the checker, the guard, and the debug line map it shifts
        $normalize = static fn (string $code): string => preg_replace([
            '/__TwigTemplate_\w+/',
            '/\n\s*\$this->sandbox = [^\n]*/',
            '/\n\s*public function ensureSecurityChecked\(\): void\n\s*\{\n.*?\n    \}\n/s',
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
            foreach (['trusted/child', 'trusted/attr', 'trusted/filters'] as $name) {
                $env->render($name, $context);
            }
            $policy->calls = 0;
            foreach (['trusted/child', 'trusted/attr', 'trusted/filters'] as $name) {
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

    public function testPreloadedTrustedTemplateRefusesToRenderInsideTheSandbox(): void
    {
        $env = $this->env();
        $wrapper = $env->load('trusted/child');

        $this->expectException(SecurityError::class);
        $this->expectExceptionMessage('was loaded as a trusted template and cannot be rendered while the sandbox is enabled');
        $env->render('trusted/sandboxed_include_wrapper', ['wrapper' => $wrapper, 'probe' => 'ok']);
    }

    public function testPreloadedTrustedMacrosRefuseToRunInsideTheSandbox(): void
    {
        $env = $this->env();
        $macros = $env->load('trusted/macros')->unwrap($env);
        $checker = $env->getExtension(SandboxExtension::class)->getChecker();

        $this->assertSame('<x>', (string) $macros->getMacroNamespace()->call('show', ['x'], [], 1, new Source('', 'test')));

        $checker->setSandboxed(true);
        try {
            $this->expectException(SecurityError::class);
            $this->expectExceptionMessage('cannot be rendered while the sandbox is enabled');
            $macros->getMacroNamespace()->call('show', ['x'], [], 1, new Source('', 'test'));
        } finally {
            $checker->setSandboxed(false);
        }
    }

    private function env(bool $optIn = true, bool $sandbox = true, ?SourcePolicyInterface $policy = null, bool $global = false): Environment
    {
        $env = new Environment(new CompileTimeSourcePolicyLoader(self::TEMPLATES), ['cache' => false, 'autoescape' => false]);
        if ($sandbox) {
            $policy ??= new CompileTimeSourcePolicyTestPolicy();
            $env->addExtension(new SandboxExtension(new SecurityPolicy(['do', 'include', 'block'], ['upper', 'join', 'map']), $global, $optIn ? $policy : new CompileTimeSourcePolicyLegacyPolicy($policy)));
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
