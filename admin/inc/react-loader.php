<?php
/**
 * Entrypoint for the theme.
 */

namespace HM\Workflows\Admin;

/**
 * Is this a development environment?
 *
 * @return bool
 */
function is_development() {
	return apply_filters( 'reactwpscripts.is_development', WP_DEBUG );
}

/**
 * Attempt to load a file at the specified path and parse its contents as JSON.
 *
 * @param string $path The path to the JSON file to load.
 * @return array|null;
 */
function load_asset_file( $path ) {
	if ( ! file_exists( $path ) ) {
		return null;
	}
	$contents = file_get_contents( $path );
	if ( empty( $path ) ) {
		return null;
	}
	return json_decode( $contents, true );
}

/**
 * Check a directory for a root or build asset manifest file, and attempt to
 * decode and return the asset list JSON if found.
 *
 * @param string $directory Root directory containing `src` and `build` directory.
 * @return array|null;
 */
function get_assets_list( string $directory ) {
	$directory = trailingslashit( $directory );
	if ( is_development() ) {
		$dev_assets = load_asset_file( $directory . 'asset-manifest.json' );
		// Fall back to build directory if there is any error loading the development manifest.
		if ( ! empty( $dev_assets ) ) {
			return filter_assets_list( $dev_assets );
		}
	}

	$production_assets = load_asset_file( $directory . 'build/asset-manifest.json' );

	if ( ! empty( $production_assets ) ) {
		// Prepend "build/" to all build-directory array paths.
		return filter_assets_list( array_map(
			function( $asset_path ) use ( $directory ) {
				// Use realpath to remove default relative path and confirm file exists.
				$real_path = realpath( $directory . 'build/' . $asset_path );
				return substr( $real_path, strpos( $real_path, 'build/' ) );
			},
			$production_assets
		) );
	}

	return null;
}

/**
 * Filter the assets to remove all async chunks, the service worker and precache manifest.
 *
 * @param array $assets
 * @return array
 */
function filter_assets_list( array $assets ) {
	return array_filter( $assets, function ( $asset_path ) {
		return ! preg_match( '/\/\d+(\.[^\/]+)?\.chunk\.|precache-manifest|service-worker/', $asset_path );
	} );
}

/**
 * Infer a base web URL for a file system path.
 *
 * @param string $path Filesystem path for which to return a URL.
 * @return string|null
 */
function infer_base_url( string $path ) {
	if ( strpos( $path, get_stylesheet_directory() ) === 0 ) {
		return get_theme_file_uri( substr( $path, strlen( get_stylesheet_directory() ) ) );
	}

	if ( strpos( $path, get_template_directory() ) === 0 ) {
		return get_theme_file_uri( substr( $path, strlen( get_template_directory() ) ) );
	}

	// Any path not known to exist within a theme is treated as a plugin path.
	$plugin_path = plugin_dir_path( $path );
	return plugin_dir_url( $path ) . substr( $path, strlen( $plugin_path ) );
}

/**
 * Return web URIs or convert relative filesystem paths to absolute paths.
 *
 * @param string $asset_path A relative filesystem path or full resource URI.
 * @param string $base_url   A base URL to prepend to relative bundle URIs.
 * @return string
 */
function get_asset_uri( string $asset_path, string $base_url ) {
	if ( strpos( $asset_path, '://' ) !== false ) {
		return $asset_path;
	}

	return trailingslashit( $base_url ) . $asset_path;
}

/**
 * @param string $directory Root directory containing `src` and `build` directory.
 * @param array $opts {
 *     @type string $base_url Root URL containing `src` and `build` directory. Only needed for production.
 *     @type string $handle   Style/script handle. (Default is last part of directory name.)
 *     @type array  $scripts  Script dependencies.
 *     @type array  $styles   Style dependencies.
 * }
 */
function enqueue_assets( $directory, $opts = [] ) {
	$defaults = [
		'base_url' => '',
		'handle'   => basename( $directory ),
		'scripts'  => [],
		'styles'   => [],
	];

	$opts = wp_parse_args( $opts, $defaults );

	$assets = get_assets_list( $directory );

	$base_url = $opts['base_url'];
	if ( empty( $base_url ) ) {
		$base_url = infer_base_url( $directory );
	}

	if ( empty( $assets ) ) {
		if ( WP_DEBUG ) {
			handle_assets_error();
		}

		trigger_error( 'React WP Scripts Error: Unable to find React asset manifest', E_USER_WARNING );
		return;
	}

	// Make runtime / bundle first up.
	uksort( $assets, function ( $asset_path ) {
		if ( strstr( $asset_path, 'runtime' ) || strstr( $asset_path, 'bundle' ) ) {
			return -1;
		}
		return 1;
	} );

	// There will be at most one JS and one CSS file in vanilla Create React App manifests.
	$has_css = false;
	foreach ( $assets as $asset_path ) {
		$is_js      = preg_match( '/\.js$/', $asset_path );
		$is_css     = preg_match( '/\.css$/', $asset_path );
		$is_runtime = preg_match( '/(runtime|bundle)/', $asset_path );

		if ( ! $is_js && ! $is_css ) {
			// Assets such as source maps and images are also listed; ignore these.
			continue;
		}

		// Set a dynamic handle as we can have more than one JS entry point.
		// Treats the runtime file as primary to make setting dependencies easier.
		$handle = $opts['handle'] . ( $is_runtime ? '' : '-' . sanitize_key( basename( $asset_path ) ) );

		if ( $is_js ) {
			wp_enqueue_script(
				$handle,
				get_asset_uri( $asset_path, $base_url ),
				$opts['scripts'],
				null,
				true
			);
		} else if ( $is_css ) {
			$has_css = true;
			wp_enqueue_style(
				$handle,
				get_asset_uri( $asset_path, $base_url ),
				$opts['styles']
			);
		}
	}

	// Ensure CSS dependencies are always loaded, even when using CSS-in-JS in
	// development.
	if ( ! $has_css ) {
		wp_register_style(
			$opts['handle'],
			null,
			$opts['styles']
		);
		wp_enqueue_style( $opts['handle'] );
	}
}


/**
 * Display an overlay error when the React bundle cannot be loaded. It also stops the execution.
 *
 * @param array $details
 */
function handle_assets_error( $details = [] ) {
	?>
	<style>
		/**
		 * Copyright (c) 2015-present, Facebook, Inc.
		 *
		 * This source code is licensed under the MIT license found in the
		 * LICENSE file in the root directory of this source tree.
		 */

		/* @flow */

		html, body {
			overflow: hidden;
		}

		.error-overlay {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			border: none;
			z-index: 1000;

			--black: #293238;
			--dark-gray: #878e91;
			--red: #ce1126;
			--red-transparent: rgba(206, 17, 38, 0.05);
			--light-red: #fccfcf;
			--yellow: #fbf5b4;
			--yellow-transparent: rgba(251, 245, 180, 0.3);
			--white: #ffffff;
		}

		.error-overlay .wrapper {
			width: 100%;
			height: 100%;
			box-sizing: border-box;
			text-align: center;
			background-color: var( --white );
		}

		.primaryErrorStyle {
			background-color: var( --red-transparent );
		}

		.error-overlay .overlay {
			position: relative;
			display: inline-flex;
			flex-direction: column;
			height: 100%;
			width: 1024px;
			max-width: 100%;
			overflow-x: hidden;
			overflow-y: auto;
			padding: 0.5rem;
			box-sizing: border-box;
			text-align: left;
			font-family: Consolas, Menlo, monospace;
			font-size: 13px;
			line-height: 2;
			color: var( --black );
		}

		.header {
			font-size: 2em;
			font-family: sans-serif;
			color: var( --red );
			white-space: pre-wrap;
			margin: 0 2rem 0.75rem 0;
			flex: 0 0 auto;
			max-height: 50%;
			overflow: auto;
		}

		.error-content {
			padding:1rem;
		}

		code {
			background-color: rgba(27,31,35,.05);
			margin: 0;
			padding: .2em .4em;
		}
	</style>
	<div class="error-overlay">
		<div class="wrapper primaryErrorStyle">
			<div class="overlay">
				<div class="header">Failed to render</div>
				<div class="error-content primaryErrorStyle">
					Unable to find React asset manifest.
					<code>react-wp-scripts</code> was unable to find either a development or production asset manifest.
					Run <code>npm start</code> to start the development server or <code>npm run build</code> to build a production bundle.
				</div>
			</div>
		</div>
	</div>
	<?php
	die();
}
