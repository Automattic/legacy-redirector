# DataForm redirect form prototype

A throwaway companion plugin that rebuilds the Add/Edit Redirect screen with
[DataForm](https://wordpress.github.io/gutenberg/?path=/docs/dataviews-dataform--docs)
and `@wordpress/ui`, next to the existing screen, so the two can be compared.
It is not part of the release (see `.distignore`) and is excluded from PHPCS.

It adds:

- **Redirects → Add Redirect (DataForm)**, and an **Edit (DataForm)** row action.
- `POST legacy-redirector-prototype/v1/redirects[/<id>]`, which saves through
  the same rules as `RedirectFormPage::handle_save()`.

## Running it

`webpack.config.js` bundles every `@wordpress/*` package except the ones that
must be shared with core (`api-fetch`, `data`, `element`, `hooks`, `i18n`).
That is what lets it run on WordPress 6.8, which has no `wp-theme` script and
older components; it was tested on 6.8.10 and 7.2-alpha. The cost is size:
about 1.8 MB of JavaScript, against about 590 KB when wp-scripts' default
externals reuse core's copies. That smaller build needs the `wp-theme` script
(WordPress 7.0 or later) and was only tried on 7.2-alpha; on 6.8 WordPress
silently drops the script and the page stays blank.

```sh
cd prototypes/dataform-redirect-form
npm install
npm run build
```

Then map it into wp-env alongside the plugin with a `.wp-env.override.json` in
the repository root:

```json
{
	"plugins": [ ".", "./prototypes/dataform-redirect-form" ]
}
```

and restart wp-env. If a Redirects page says you are not allowed to access it,
load the dashboard once first.
