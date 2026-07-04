# LLMs.txt Generator - Settings

The LLMs.txt Generator is a Joomla task plugin that builds a curated `/llms.txt` file from your menus and content and writes it to your site root. It runs through the Joomla Scheduler (`com_scheduler`) as a recurring task, so the file stays up to date automatically.

This page documents every setting. The settings live on the scheduled task itself: go to **System > Scheduled Tasks**, open (or create) a task of type **Generate llms.txt**, and configure it under the **llms.txt** tab.

## How it works

Each time the task runs, the plugin reads its parameters and rebuilds the file from scratch:

1. A header made up of a title, a summary and an optional intro paragraph.
2. One or more sections, each pulling a list of links from a menu, from content categories, or from your articles.
3. The result is written to your site root as `llms.txt` (or the file name you choose).

Only links that a guest visitor is allowed to see are included. The plugin filters every item against the public and guest view access levels, so it never leaks pages that require a login.

## Setting up the scheduled task

When you install the plugin it enables itself and creates a daily task automatically, set to run at 03:00 (server time, UTC) with your main menu as the first section. You can change the schedule, the sections, and every other option on the task.

If you run Joomla's scheduler through a real system cron on the command line, set the **Site URL** option below. For web-cron (the default lazy scheduler that triggers on site visits) you can leave it empty.

## General settings

These apply to the whole file.

| Setting | Description |
|---------|-------------|
| **Site URL** | Absolute URL of your site, for example `https://www.example.com`. This is only needed when the task runs from a CLI cron job, where Joomla cannot detect the host on its own. It is used to build the links in the file. Leave it empty to auto-detect, which is the right choice when you run the task through the web-cron. |
| **Output file** | The file name written to your site root. The llms.txt standard expects it to be called `llms.txt`, so only change this if you have a specific reason. |
| **Remove URL language code** | On a multilingual site Joomla adds a language prefix to URLs, for example `/en/page`. Turn this on to strip that prefix so links become `/page`. It only removes a segment that matches a published language code, and it never touches external links. |
| **Title (H1)** | The main heading of the file, rendered as `# Title`. Leave it empty to use your site name. |
| **Summary (blockquote)** | One or two sentences describing your site. Rendered as a `>` blockquote directly under the title. |
| **Intro paragraph** | An optional paragraph with extra context, shown after the summary. |

## Sections

Sections are the heart of the file. Each section becomes an `## Heading` followed by a curated list of links formatted as `- [title](url): description`. Add as many sections as you need with the plus button; drag to reorder them.

A tip from the llms.txt standard: if you name a section **Optional**, agents may skip it when they are short on context. Use that for secondary material.

### Choosing a source

Every section pulls its links from one **Source**. The source you pick determines which of the fields below appear.

| Source | What it lists |
|--------|---------------|
| **Menu** | The items of a single site menu. |
| **Content categories** | Articles from one or more content categories. |
| **Articles (all)** | All published articles, without a category restriction. |

### Common fields

These fields are available for every source.

| Setting | Description |
|---------|-------------|
| **Heading** | The `## Heading` for this section in the file. |
| **Language** | Only include items in this language. Language-neutral items are always kept. Leave it on *All languages* to disable the filter. |
| **Descriptions** | Where the text after each link comes from. *Automatic* uses the meta description and falls back to the intro text. You can also force *Meta description only*, an *Intro text snippet*, or *No descriptions*. |
| **Max items** | The maximum number of links in this section. Set to `0` for no limit. Defaults to 50. |

### Menu source

When **Source** is set to *Menu*, these fields appear.

| Setting | Description |
|---------|-------------|
| **Menu** | The site menu to list. |
| **Only these items** | Limit the section to the menu items you select here. Leave empty to include every item of the menu. |
| **Exclude items** | Menu items to leave out of the section. |
| **Include submenu items** | On by default. Includes nested menu items at every level. Turn it off to list only the top-level items. When you use *Only these items*, that explicit selection takes priority over this toggle. |

The include and exclude pickers only show the items of the menu you chose for this section.

### Content categories source

When **Source** is set to *Content categories*, these fields appear.

| Setting | Description |
|---------|-------------|
| **Categories** | One or more content categories to pull articles from. |
| **Include subcategories** | Off by default. Turn it on to also include articles from the subcategories of the selected categories. |

The article, age and ordering fields below also apply.

### Articles source

When **Source** is set to *Articles (all)*, the section lists every published article. Use the article, age and ordering fields to narrow and sort the list.

### Article, age and ordering fields

These fields apply to both the *Content categories* and *Articles (all)* sources.

| Setting | Description |
|---------|-------------|
| **Only these articles** | Limit the section to the articles you select. Leave empty to include all matching articles. |
| **Exclude articles** | Articles to leave out of the section. |
| **Maximum age (days)** | Only include articles published within this many days, based on the publish date (falling back to the created date). Use `0` to show all. |
| **Order** | How the links are sorted: *Newest first*, *Category order*, or *Title A to Z*. |

## Verifying the result

After the task runs, open `https://your-site.com/llms.txt` in a browser. The success is also logged on the task: the log records the file that was written and its size. If the file cannot be written, the log tells you whether the site root or the existing file is not writable, and the task ends with a failure status.

## Related

Making your site AI-friendly? Pair this plugin with the [Joomill Markdown plugin](https://www.joomill-extensions.com/extensions/markdown-alternate) to serve your articles as clean Markdown for AI agents and LLMs.
