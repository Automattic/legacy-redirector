/**
 * Bundle every @wordpress package except those that must be shared with core
 * (the REST nonce, translations, stores and React), so the form does not
 * depend on script handles or component versions that WordPress 6.8 lacks.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const SHARED_WITH_CORE = [
	'@wordpress/api-fetch',
	'@wordpress/data',
	'@wordpress/element',
	'@wordpress/hooks',
	'@wordpress/i18n',
];

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => ! ( plugin instanceof DependencyExtractionWebpackPlugin )
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) =>
				request.startsWith( '@wordpress/' ) && ! SHARED_WITH_CORE.includes( request )
					? false
					: undefined,
		} ),
	],
};
