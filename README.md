# Zyppy Search

Live page search for Contao 5. A visitor types into the search box and the
results appear underneath as they type, without a page reload — each one with
the page's image and teaser from Zyppy Page alongside the usual highlighted
excerpt. A page flagged as a news reader shows the matching news article's
teaser and image instead.

Underneath it is Contao's own site search: the same `tl_search` index, the
same query options and the same per-visitor access rules. What this bundle
adds is the live transport and the richer result.

## Requirements

- PHP 8.1 or newer
- Contao 5.3 or newer, with no upper bound. The Zyppy Suite supports the LTS
  releases and is tested against the current non-LTS release as well: the
  test suite passes on core-bundle 5.3.0, 5.7.13 and 6.0.2 (last run
  2026-10-07).
- `asconsulting/contao-zyppy-page` 5.x, installed automatically as a
  dependency. It provides the `page_image` and `page_teaser` fields the results
  are built from.
- Optional: `contao/news-bundle` for the **News Reader** override, and
  `asconsulting/contao-zyppy-popup` for the **Clear on Popup** option.
- No jQuery. The client script is plain JavaScript.

## Installation

```bash
composer require asconsulting/contao-zyppy-search:^5.0
```

Then run the database migration. Contao Manager does this on install and on
update; on the command line it is `vendor/bin/contao-console contao:migrate`.
It adds the module settings to `tl_module` and the **News Reader** flag to
`tl_page`.

The module searches Contao's search index; it does not build it. Index the
site as usual (crawl it, or let the front end indexer run) before expecting
results.

## Usage

### Creating the module

Under **Themes → Front end modules**, create a module of type **Zyppy Search**
(in the **Applications** group) and place it on a page like any other module.
Its settings are the ones Contao's own search module has, plus three
formatting options:

| Setting | Effect |
|---|---|
| Query type (`queryType`), fuzzy search (`fuzzy`), context range (`contextLength`), minimum keyword length (`minKeywordLength`), items per page (`perPage`) | As in Contao's search module. The minimum keyword length also decides how many characters the visitor has to type before the live search fires (3 if unset). |
| Form layout (`searchType`) | *Advanced* adds the "match all / match any" choice to the form; the live search honours it too. |
| Reference pages (`pages`) | Restricts the search to these pages and their subtrees. Left empty, the module searches the whole site the request host resolves to. |
| Redirect page (`jumpTo`) | The page the form submits to when JavaScript is off. Left empty, the form submits back to its own page. |
| Search template (`searchTpl`) | The template for one result. `search_zyppy` by default. |
| Image size (`imgSize`) | The image size used for the result image. |
| **Format Page Teaser** / **Page Teaser Limit** | Strip tags from the page teaser and optionally cut it to this many characters (0 for unlimited). |
| **Format Page Description** / **Page Description Limit** | The same for the page's meta description. |
| **Format News Teaser** / **News Teaser Limit** | The same for the news teaser used by the News Reader override. |
| **Clear on Popup** (`popupClear`) | Only present with `contao-zyppy-popup` installed: empties the results when a pop-up opens. |

### What the visitor gets

- **Typing.** 300 ms after the last keystroke, once the minimum length is
  reached, the results are fetched and dropped into the results area. A new
  keystroke cancels a request still in flight; clearing the box clears the
  results. Two search modules on one page do not interfere with each other.
- **Without JavaScript.** The form submits as an ordinary GET and the first
  page of results is rendered on the server. The pager is real links, so it
  works the same way. Both paths render through one template, so the markup is
  identical.
- **Each result** shows the page title linked to the page, its relevance, the
  page image (if `page_image` is set, at the chosen image size), the page
  teaser, and the highlighted context excerpt.
- **Protected pages** appear only for visitors allowed to see them, by the
  same rule core applies. A module itself restricted to member groups answers
  nobody else, on either path.

### The News Reader override

On a news reader page, tick **News Reader** (`zyppy_news`) in the page's
**Meta** legend; the box is offered on regular pages only. When a result points
at a news article under that page, the article's teaser and image replace the
page's own. Only published articles inside their publication window are used.
Requires `contao/news-bundle`.

### The live endpoint

The script calls `GET /_zyppy/search/{moduleId}` with `keywords`, an optional
`query_type` (`and` or `or`) and an optional `page`, and receives JSON: the
result window (`count`, `from`, `to`, `page`, `totalPages`) plus one `html`
string that is dropped into the results area as is.

- **Rate limited:** 30 requests per minute per client IP, sliding window. Over
  the limit the endpoint answers 429 with a `Retry-After` header.
- Responses are `private, no-store` and carry `X-Robots-Tag: noindex`;
  results are member scoped and must never sit in a shared cache.
- 404 for an unknown module id or an out-of-range page, 403 for a module the
  visitor may not see.

### Templates

| Template | Role |
|---|---|
| `zyppy_search` (`frontend_module/zyppy_search`) | The module shell: form and results area. The module's default template. |
| `search_zyppy` | One result. Selectable per module through **Search template**, which is the one an editor has a reason to swap. |
| `search_zyppy_results` | Everything inside the results area: header, keyword hint, the result rows, pager. Not selectable in the back end; override it by shipping a project template of the same name. |
| `mod_search_zyppy` | The legacy shell, used only when it is picked as a module's **Custom template**. Kept so sites that chose it keep rendering. |

The wrapper class `mod_zyppy_search` is load bearing: the script selects it,
and `contao-zyppy-popup` relies on it for **Clear on Popup**. The script is
published at `bundles/search/js/search.js` and is emitted by the module
template itself, so nothing has to be added to the page layout.

## Migration from legacy version

This package replaces the legacy package `asconsulting/zyppy_search` (namespace
`ZyppySearch\`). The old name does not resolve any more.

The four legacy Zyppy packages — page, classes, popup and search — have to be
upgraded **together, in one `composer update`**: old and new packages register
the same DCA fields and module types, so a site that briefly has both installed
double-registers. The walkthrough for the whole suite, including the order of
operations and a verification checklist, is `UPGRADING.md` in the
`contao-zyppy-page` repository. The part specific to this package:

- **Columns.** `tl_page` is unchanged (`zyppy_news`). `tl_module` keeps the six
  format/limit fields and drops `ajaxTpl` and `disableAjax`, which no code ever
  read. Accept the DROP when `contao:migrate` offers it; nothing to carry over.
- **The module type key `zyppy_search` is unchanged.** Existing module rows keep
  resolving; there is no `tl_module` migration to run.
- **Templates.** `mod_search_zyppy` and `search_zyppy` moved from `.html5` to
  `.html.twig` under the same names; `search_zyppy_results` and `zyppy_search`
  are new. A site override of either old template must be ported to Twig and
  the `.html5` copy deleted — on Contao 5 the `.html5` copy silently keeps
  winning; on Contao 6 it breaks.
- **If a module has a Custom template set,** confirm it still resolves after
  the upgrade before calling the site done.
- **The transport changed.** The live search is a JSON route with a rate
  limiter now, instead of a page render that stopped halfway. That is
  behaviour, not data — nothing to migrate, but exercise the search box once.
  The `customizeSearch` hook is no longer called.
- **Results on news reader pages change visibly.** The legacy module computed the
  news teaser and image on every result and rendered neither; 5.0.0 shows
  them.
- `contao-zyppy-page` is now a hard requirement rather than a suggestion.

## Development

```
composer install
vendor/bin/phpunit --no-coverage
```

Two things to know before changing the code:

- `PageScopeResolver` is a security boundary. The pages a module may search
  come from its own settings or from the request host — never from a request
  parameter. Adding one would let any visitor widen their own search scope.
- `ResultRenderer` is the one renderer for both the server-rendered first page
  and every live update. Two renderers would make **Search template** apply to
  the first paint and silently not to the updates after it.

`TODO.md` carries the original audit, the modernization steps and what has been
verified where.

## Licence

Licensed under the GNU Affero General Public License v3.0 only (AGPL-3.0-only). See [LICENSE](LICENSE). Copyright Andrew Stevens.
