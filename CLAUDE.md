# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Joomla 5 **task plugin** (`group="task"`) that generates a curated `/llms.txt` file
from the site's menus and content and writes it to the Joomla web root. It is meant to
be run by the Joomla Scheduler component (`com_scheduler`) as a recurring task, so the
file stays up to date automatically.

Vendor namespace: `Joomill\Plugin\Task\Llmstxt` (mapped to `src/` via the manifest).

## No build / test tooling

There is no Composer, npm, linter, or test suite in this repo. The source ships as-is.
Joomla provides the runtime (`Joomla\CMS\*`, `Joomla\Database`, `Joomla\Registry`, etc.);
those classes are not vendored here, so static analysis outside a Joomla install will
report the framework classes as missing. Verify changes by installing into a real Joomla 5 site.

### Packaging and install

The extension is installed by zipping the contents of this folder and installing the zip
through Joomla's Extensions > Install, or by pointing a discover-install at the folder.
The manifest `llmstxt.xml` is the install descriptor; `<version>` there and the folder
name (`plg_task_llmstxt_v1.0.0`) must stay in sync when bumping versions.

## Architecture

Three moving parts, deliberately separated:

1. **`services/provider.php`** — DI service provider. Standard Joomla plugin bootstrap:
   constructs `Extension\Llmstxt` and registers it as the `PluginInterface`.

2. **`src/Extension/Llmstxt.php`** — the plugin. Uses `TaskPluginTrait` and subscribes to
   the scheduler events. `TASKS_MAP` advertises one routine, `llmstxt_generate`, whose
   `generate()` method is the entry point. This class owns **all side effects**: it reads
   task params, resolves the output filename (always `basename()` of the site root, never
   a path), checks writability, writes the file via `Joomla\Filesystem\File`, and logs
   outcomes through `logTask()`. It returns a scheduler `Status` (`OK` / `KNOCKOUT`).

3. **`src/Generator/LlmstxtGenerator.php`** — pure content builder. Given the task params
   it returns the llms.txt markdown as a **string with no side effects** (the plugin writes
   it). This is where the file format lives:
   - `# Title` (H1) → `> Summary` blockquote → intro paragraph → one `## Heading` per section.
   - Each section pulls links from either a **menu** (`#__menu`) or **content categories**
     (`#__content`), formatted as `- [title](url): description`.
   - **Access filtering is critical**: only rows whose `access` is in
     `Access::getAuthorisedViewLevels(0)` (public/guest view levels) are emitted. Never
     leak links that a guest could not see. Keep this check when touching either query.
   - URLs are built with the SEF router (`Route::link` / `RouteHelper::getArticleRoute`).
     `baseUrl` (the "Site URL" param) exists for **CLI cron** runs where `Uri::root()`
     cannot auto-detect the host; `normaliseHost()` / `absolutise()` rewrite the
     auto-detected root to that configured base. Leave `baseUrl` empty for web-cron.
   - `oneLine()` / `snippet()` sanitise all text: strip `{shortcodes}`, strip tags,
     decode entities, collapse whitespace. Any new text output must go through these.

4. **`src/Field/MenutypeField.php`** — custom form field (`type="menutype"`) listing site
   menus from `#__menu_types`. Referenced in the subform via `addfieldprefix`.

5. **`forms/llmstxt.xml`** — the task's parameter form: general header fields plus a
   repeatable `sections` subform. Field `showon` rules drive which inputs appear per
   section source (menu vs category). Form field names map 1:1 to the `$params`/`$section`
   properties the generator reads — keep them aligned.

## Conventions for this repo

- Follow the user's global Joomla manifest standard (element order, section comments,
  values) from the Obsidian snippet `30-snippets/joomla-extension-manifest.md` whenever
  you touch `llmstxt.xml`.
- **Never translate the extension name string** `PLG_TASK_LLMSTXT` — it is identical in
  every language file. All other language strings are translated. Languages present:
  `en-GB` and `nl-NL`, each with a runtime `.ini` and a `.sys.ini`. Add every new string
  to both languages.
- Language constants are prefixed `PLG_TASK_LLMSTXT_*`; the routine uses the
  `langConstPrefix` `PLG_TASK_LLMSTXT_GENERATE`.
- All PHP files start with the standard Joomla docblock and `defined('_JEXEC') or die;`.
