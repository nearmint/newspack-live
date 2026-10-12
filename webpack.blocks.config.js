/**
 * Webpack config for the blocks build (block.json-driven, with script modules).
 *
 * It is the stock @wordpress/scripts config; this file only exists so the blocks build
 * doesn't pick up webpack.config.js, which is the admin bundles config.
 */
module.exports = require( '@wordpress/scripts/config/webpack.config' );
