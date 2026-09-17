let translations = {};

// Get translations for public features that are present on this page.
if ( window.hasOwnProperty( 'wprmp_public_feature_translations' ) ) {
    translations = {
        ...translations,
        ...window.wprmp_public_feature_translations,
    };
}

// Get public translations. Load these after feature translations so existing
// public translation filters retain their ability to override any key.
if ( window.hasOwnProperty( 'wprm_public' ) && wprm_public.hasOwnProperty( 'translations' ) ) {
    translations = {
        ...translations,
        ...wprm_public.translations,
    };
}

// Get admin translations.
if ( window.hasOwnProperty( 'wprm_admin' ) && wprm_admin.hasOwnProperty( 'translations' ) ) {
    translations = {
        ...translations,
        ...wprm_admin.translations,
    };
}

export function __wprm( text, domain = 'wp-recipe-maker' ) {
    if ( translations.hasOwnProperty( text ) ) {
        return translations[ text ];
    } else {
        return text;
    }
};
