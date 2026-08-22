# getgrav/Twig — a patched fork, not a mirror

This repo is Grav's fork of twigphp/Twig. It tracks upstream `3.x` closely and is re-synced wholesale, but it carries a small set of **deliberate patches that Grav will break without**. The whole risk of this repo is that a sync quietly drops one, or re-homes one onto rewritten upstream code so it still looks present but no longer works.

That is not hypothetical. It is exactly how [getgrav/grav#4256](https://github.com/getgrav/grav/issues/4256) shipped: upstream 3.27 replaced the recursive `Parser::filterBodyNodes()` with the flat `Parser::cleanupBodyForChildTemplates()`, the Grav patch was carried across onto the new method, and the re-home missed one case. The diff still showed a Grav patch in `Parser.php`, the fork's own test suite was green, and every form field on every Grav 2.0.20 site printed its HTML attributes as visible text.

Grav pulls this fork by **branch, not tag**: core's `composer.json` says `"twig/twig": "3.x-dev"`, with the repo declared under `repositories`. Pushing `3.x` is all that is needed for composer to pick a change up. **Never tag this repo** for a Grav release; `composer.lock` pins the commit.

## The patches

Run `./grav-fork-check.sh` for the live list. As of 2026-08-22 there are three, all in `src/`:

| File | Patch | How it fails if lost |
| --- | --- | --- |
| `Extension/EscaperExtension.php` | Class is **not final** (upstream made it final in 3.10) | **Silently.** `Grav\Common\Twig\TwigEnvironment::getExtension()` returns a subclass shimming the pre-3.9 `setEscaper()` call site, and it guards with `isFinal()` — so restoring `final` just stops the shim, with no error. |
| `NodeVisitor/CorrectnessNodeVisitor.php` | `isTransparentTag()` lets a `block` definition sit under an `if` | Loudly: `SyntaxError`, "A block definition cannot be nested under non-capturing nodes." |
| `Parser.php` | `cleanupTransparentBodyNodes()` strips those nested block references from a child template's body | Quietly wrong output: the block renders where it was declared *as well as* through the parent. |

Every patch is marked with a `GRAV FORK:` comment explaining what depends on it. **Keep that convention** — the check script greps for those markers, and a marker is the only thing telling the next person why an innocuous-looking line matters.

Two of these patches must also stay **narrow**, and that is as easy to break as losing them:

- Only `if` is transparent. A `block` under `for`, `embed`, etc. must still be a parse error.
- Capturing tags such as `set` are left alone, because a block legitimately *does* render in place there. Grav's form plugin builds its field classes that way, so over-stripping would silently empty them.

## After every upstream sync

```bash
./grav-fork-check.sh                       # or: ./grav-fork-check.sh <fork> <grav-core>
```

It fetches upstream, prints the full `src/` divergence, lists the `GRAV FORK` markers, and runs the Grav core tests that pin the patches. Then, by hand:

1. **Read every hunk** of `git diff $(git merge-base HEAD upstream/3.x) HEAD -- src/`. Confirm each is a patch you meant to keep and that it still does what its `GRAV FORK` comment claims, against the *current* upstream code around it.
2. **Run this repo's suite** — `vendor/bin/simple-phpunit`. The Grav-specific fixtures live in `tests/Fixtures/tags/inheritance/conditional_block*.test`.
3. **Point Grav core at the new commit and run its unit suite.** In the core repo: `composer update twig/twig && php -d register_argc_argv=On vendor/bin/codecept run unit`. This is the step that catches a bad re-home; nothing in this repo can.
4. **Reconcile `TwigForkPatchesTest.php`** in core (`tests/unit/Grav/Common/Twig/`) against the divergence list. Any patch without a case there is unguarded. Depth for the parser patches lives in `TwigConditionalBlockTest.php` and `DeferredExtensionTest.php`.

A green suite only proves the divergences someone already wrote a test for. If the sync introduces a *new* patch, it needs a new test in the same pass, or the next sync inherits the same blind spot.

## Things worth knowing

- Deferred blocks ride the parser patch too: `DeferredTokenParser` (vendored in Grav core, not here) returns a plain `BlockReferenceNode`, so anything affecting block references affects `{% block x deferred %}`.
- Twig 3.28 deprecates `extends` and `use` anywhere but a template's root, and Twig 4 rejects them. Grav templates have been fixed for this; if a sync turns that deprecation into an error, that is upstream working as announced, not a regression here.
