export default {
    stripAdjustableShortcodes( text = '' ) {
        return String( text ).replace( /\[\/?adjustable]/ig, '' ).trim();
    },
    stripHtml( text = '' ) {
        return String( text ).replace( /(<([^>]+)>)/ig, '' ).trim();
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
