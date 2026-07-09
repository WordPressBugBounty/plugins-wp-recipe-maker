export default {
    stripAdjustableShortcodes( text = '' ) {
        return String( text ).replace( /\[\/?adjustable]/ig, '' ).trim();
    },
    stripHtml( text = '' ) {
        return String( text ).replace( /(<([^>]+)>)/ig, '' ).trim();
    },
    getConnectorElisionSettings() {
        const adminSettings = typeof wprm_admin !== 'undefined' && wprm_admin.settings ? wprm_admin.settings : false;
        const publicSettings = typeof wprm_public !== 'undefined' && wprm_public.settings ? wprm_public.settings : false;
        const settings = adminSettings || publicSettings || {};

        return settings.ingredient_unit_connector_elision || {};
    },
    normalizeConnectorWord( word = '' ) {
        let normalized = String( word ).trim().toLowerCase();

        if ( normalized.normalize ) {
            normalized = normalized.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
        }

        return normalized
            .replace( /œ/g, 'oe' )
            .replace( /æ/g, 'ae' );
    },
    getFirstIngredientWord( name = '' ) {
        const cleaned = this.stripAdjustableShortcodes( this.stripHtml( name ) )
            .replace( /^[^A-Za-zÀ-ÖØ-öø-ÿŒœÆæ]+/, '' );
        const match = cleaned.match( /^[A-Za-zÀ-ÖØ-öø-ÿŒœÆæ']+/ );

        return match ? match[0] : '';
    },
    shouldElideConnector( connectorData = {}, name = '' ) {
        if ( ! connectorData.connector ) {
            return false;
        }

        const settings = this.getConnectorElisionSettings();
        if ( ! settings.language ) {
            return false;
        }

        const connector = String( connectorData.connector ).trim().toLowerCase();
        const connectors = settings.connectors && 'object' === typeof settings.connectors ? settings.connectors : {};
        if ( ! connectors[ connector ] ) {
            return false;
        }

        const word = this.getFirstIngredientWord( name );
        if ( ! word ) {
            return false;
        }

        const normalizedWord = this.normalizeConnectorWord( word );
        const hAspireWords = Array.isArray( settings.h_aspire_words ) ? settings.h_aspire_words : [];

        if ( 'fr' === settings.language && hAspireWords.includes( normalizedWord ) ) {
            return false;
        }

        return settings.allow_h_elision ? /^[aeiouy]|^h[aeiouy]/.test( normalizedWord ) : /^[aeiouy]/.test( normalizedWord );
    },
    resolveConnectorDataForName( connectorData = false, name = '' ) {
        if ( ! connectorData || ! connectorData.connector || ! this.shouldElideConnector( connectorData, name ) ) {
            return connectorData;
        }

        const settings = this.getConnectorElisionSettings();
        const connector = String( connectorData.connector ).trim().toLowerCase();
        const connectors = settings.connectors && 'object' === typeof settings.connectors ? settings.connectors : {};

        return {
            ...connectorData,
            connector: connectors[ connector ] || "d'",
            connector_spacing: 'space-before',
        };
    },
    getConnectorSpacing( spacing = 'space-both' ) {
        switch ( spacing ) {
            case 'space-before':
                return { before: ' ', after: '' };
            case 'space-after':
                return { before: '', after: ' ' };
            case 'no-space':
                return { before: '', after: '' };
            default:
                return { before: ' ', after: ' ' };
        }
    },
    getIngredientConnectorData( ingredient = {} ) {
        if ( ingredient.unit_connector ) {
            return {
                connector: ingredient.unit_connector,
                connector_spacing: ingredient.unit_connector_spacing || 'space-both',
            };
        }

        if (
            ingredient.unit_systems
            && ingredient.unit_systems[ 'unit-system-1' ]
            && ingredient.unit_systems[ 'unit-system-1' ].unit_connector
        ) {
            return {
                connector: ingredient.unit_systems[ 'unit-system-1' ].unit_connector,
                connector_spacing: ingredient.unit_systems[ 'unit-system-1' ].unit_connector_spacing || 'space-both',
            };
        }

        if ( 'undefined' !== typeof window && window.wprm_admin_modal_ingredient_unit_connectors && ingredient.unit ) {
            const unit = this.stripHtml( ingredient.unit ).toLowerCase();
            const cached = window.wprm_admin_modal_ingredient_unit_connectors[ unit ];

            if ( cached && cached.connector ) {
                return {
                    connector: cached.connector,
                    connector_spacing: cached.connector_spacing || 'space-both',
                };
            }
        }

        return false;
    },
    joinAmountUnitAndName( amountUnit = '', name = '', connectorData = false ) {
        amountUnit = undefined === amountUnit || null === amountUnit ? '' : String( amountUnit ).trim();
        name = undefined === name || null === name ? '' : String( name ).trim();

        if ( ! amountUnit || ! name ) {
            return amountUnit + name;
        }

        if ( connectorData && connectorData.connector ) {
            connectorData = this.resolveConnectorDataForName( connectorData, name );
            const spacing = this.getConnectorSpacing( connectorData.connector_spacing );
            return amountUnit + spacing.before + connectorData.connector + spacing.after + name;
        }

        return amountUnit + ' ' + name;
    },
    getIngredientString( ingredient, includeNotes = true ) {
        let ingredientString = '';

        let amountUnitFields = [];
        if ( ingredient.amount ) { amountUnitFields.push( ingredient.amount ); }
        if ( ingredient.unit ) { amountUnitFields.push( ingredient.unit ); }

        ingredientString = this.joinAmountUnitAndName(
            amountUnitFields.join( ' ' ),
            ingredient.name || '',
            this.getIngredientConnectorData( ingredient )
        );

        if ( includeNotes && ingredient.notes ) {
            ingredientString = [ ingredientString, ingredient.notes ].filter( Boolean ).join( ' ' );
        }
        
        if ( ingredientString ) {
            // Remove HTML elements.
            ingredientString = this.stripHtml( ingredientString );

            // Remove adjustable shortcodes.
            ingredientString = this.stripAdjustableShortcodes( ingredientString );
        }

        return ingredientString;
    },
};
