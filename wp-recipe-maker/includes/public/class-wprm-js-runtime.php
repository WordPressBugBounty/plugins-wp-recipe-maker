<?php
/** Versioned JS bridge for independently installed plugin packages. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WPRM_JS_Runtime {
    /** Released 10.8.x uses the historical Webpack protocol, audited per archive. */
    public static function legacy_config() {
        if ( ! defined( 'WPRMP_VERSION' ) || ! defined( 'WPRMP_DIR' ) || ! WPRM_Addons::is_active( 'premium' ) ) { return false; }
        if ( version_compare( WPRMP_VERSION, '10.8.0', '<' ) || version_compare( WPRMP_VERSION, '10.9.0', '>=' ) ) { return false; }
        static $profiles = null;
        if ( null === $profiles ) {
            $profiles = json_decode( file_get_contents( WPRM_DIR . 'includes/public/js-legacy-profiles.json' ), true );
        }
        $key = strtolower( WPRMP_BUNDLE ) . '-' . WPRMP_VERSION;
        if ( ! isset( $profiles[ $key ] ) ) { return false; }
        $profile = $profiles[ $key ];
        // Filenames are taken from released artifacts, never guessed from Free's build.
        return array(
            'profile' => $key,
            'assetsUrl' => WPRMP_URL . 'dist/',
            'files' => $profile['files'],
            'printSharesQueue' => version_compare( WPRMP_VERSION, '10.8.2', '<' ),
            'pdf' => $profile['files']['438'],
        );
    }
}
