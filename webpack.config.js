/**
 * Webpack config for the admin bundles (live console, editor panel).
 *
 * Blocks (with their script modules) are built separately by `npm run build:blocks`,
 * because the modules build returns a multi-config that getWebpackConfig can't merge.
 */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

const candidates = {
	console: './src/console/index.tsx',
	'editor-panel': './src/editor-panel/index.tsx',
};

const entry = Object.fromEntries( Object.entries( candidates ).filter( ( [ , file ] ) => fs.existsSync( path.resolve( __dirname, file ) ) ) );

module.exports = getBaseWebpackConfig( { entry } );
