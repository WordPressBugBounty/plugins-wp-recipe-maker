window.WPRecipeMaker = typeof window.WPRecipeMaker === "undefined" ? {} : window.WPRecipeMaker;

window.WPRecipeMaker.debug = {
	version: () => {
		return wprm_public.version;
	},
	recipe: () => {
		const recipes = WPRecipeMaker.manager.findRecipesOnPage();

		if ( recipes ) {
			return WPRecipeMaker.manager.getRecipeImmediately( recipes[0] );
		}

		return false;
	},
	getAdminBarMetadataText: ( index ) => {
		const pre = document.querySelector( `#wp-admin-bar-wprm-debug-entry-${ index }-panel pre` );

		if ( ! pre ) {
			return '';
		}

		return pre.textContent || '';
	},
	getAdminBarMetadataValidationCode: ( index ) => {
		const text = window.WPRecipeMaker.debug.getAdminBarMetadataText( index );

		if ( ! text ) {
			return '';
		}

		return `<script type="application/ld+json">\n${ text }\n</script>`;
	},
	setAdminBarActionState: ( element, label ) => {
		if ( ! element ) {
			return;
		}

		const originalText = element.dataset.wprmOriginalText || element.textContent;
		element.dataset.wprmOriginalText = originalText;
		element.textContent = label;

		window.setTimeout( () => {
			element.textContent = originalText;
		}, 1500 );
	},
	copyText: async ( text ) => {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			await navigator.clipboard.writeText( text );
			return true;
		}

		throw new Error( 'Clipboard API unavailable.' );
	},
	copyAdminBarMetadata: ( index, element = null ) => {
		const text = window.WPRecipeMaker.debug.getAdminBarMetadataText( index );

		if ( ! text ) {
			return false;
		}

		window.WPRecipeMaker.debug.copyText( text ).then( () => {
			window.WPRecipeMaker.debug.setAdminBarActionState( element, 'Copied' );
		} ).catch( () => {
			window.prompt( 'Copy the metadata below:', text );
		} );

		return false;
	},
	prepareAdminBarValidator: ( index, validator, element = null ) => {
		const code = window.WPRecipeMaker.debug.getAdminBarMetadataValidationCode( index );

		if ( ! code ) {
			return;
		}

		window.WPRecipeMaker.debug.copyText( code ).then( () => {
			window.WPRecipeMaker.debug.setAdminBarActionState( element, 'Opened' );
		} ).catch( () => {
			window.setTimeout( () => {
				window.prompt( 'Paste this code into the validator:', code );
			}, 50 );
		} );
	},
};
