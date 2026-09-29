<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Node;

use Twig\Attribute\YieldReady;
use Twig\Compiler;

/**
 * GRAV FORK: compile-time source sandboxing, see CompileTimeSourcePolicyInterface.
 *
 * Takes the place of CheckSecurityNode in a template compiled as trusted, which
 * has no sandbox checks of its own. Twig calls ensureSecurityChecked() before it
 * renders a template, one of its blocks or macros, or resolves its parent, so
 * this refuses to run the template while the sandbox is on. Templates loaded
 * while the runtime flag is on get a fully checked class instead, so this only
 * fires for an instance loaded earlier (a TemplateWrapper passed into a
 * sandboxed include, for instance), which fails closed.
 *
 * If this node is lost, a trusted template rendered inside a sandboxed render
 * runs unchecked, silently. Grav's SourceSandboxCompilationTest pins it.
 *
 * @internal
 */
#[YieldReady]
final class TrustedTemplateGuardNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->write("\n")
            ->write("public function ensureSecurityChecked(): void\n")
            ->write("{\n")
            ->indent()
            ->write("if (\$this->sandbox->isSandboxed()) {\n")
            ->indent()
            ->write("throw new \\Twig\\Sandbox\\SecurityError(\\sprintf('Template \"%s\" was loaded as a trusted template and cannot be rendered while the sandbox is enabled.', \$this->getTemplateName()), -1, \$this->source);\n")
            ->outdent()
            ->write("}\n")
            ->outdent()
            ->write("}\n")
        ;
    }
}
