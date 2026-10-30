window.WPRecipeMaker = typeof window.WPRecipeMaker === "undefined" ? {} : window.WPRecipeMaker;

window.WPRecipeMaker.manager = {
	init: () => {
	},
	recipes: {},
	findRecipesOnPage: () => {
		let recipes = [];
		const potentialRecipes = document.querySelectorAll( '*[data-recipe-id]' );

		for ( let potentialRecipe of potentialRecipes ) {
			const id = parseInt( potentialRecipe.dataset.recipeId );

			if ( id ) {
				recipes.push( id );
			}
		}

		// Return unique IDs.
		return [ ...new Set( recipes ) ];
	},
	resetRecipe: ( id ) => {
		if ( window.WPRecipeMaker.manager.recipes.hasOwnProperty( `recipe-${id}` ) ) {
			delete window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ];
		}
	},
	getRecipe: ( id, loadDifferentId = false ) => {
		id = 'preview' === id ? id : parseInt( id );

		if ( ! window.WPRecipeMaker.manager.recipes.hasOwnProperty( `recipe-${id}` ) ) {
			return window.WPRecipeMaker.manager.loadRecipe( id, loadDifferentId );
		}

		return Promise.resolve( window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ] );
	},
	getRecipeImmediately: ( id ) => {
		id = 'preview' === id ? id : parseInt( id );

		let recipe = window.WPRecipeMaker.manager.recipes.hasOwnProperty( `recipe-${id}` ) ? window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ] : false;

		// Check if data does not need to be loaded through API.
		if ( ! recipe ) {
			if ( window.hasOwnProperty( 'wprm_recipes' ) && window.wprm_recipes.hasOwnProperty( `recipe-${id}` ) ) {
				const recipeData = window.wprm_recipes[ `recipe-${id}` ];
				recipe = window.WPRecipeMaker.manager.loadRecipeObject( id, recipeData );
				window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ] = recipe;
			}
		}

		return recipe;
	},
	loadRecipe: ( id, loadDifferentId = false ) => {
		return new Promise((resolveRecipe, rejectRecipe) => {
			// Check for data added in PHP of try loading through API.
			const getRecipeData = new Promise((resolveData, rejectData) => {
				const idToGetDataFor = loadDifferentId ? loadDifferentId : id;

				if ( window.hasOwnProperty( 'wprm_recipes' ) && window.wprm_recipes.hasOwnProperty( `recipe-${idToGetDataFor}` ) ) {
					resolveData( window.wprm_recipes[ `recipe-${idToGetDataFor}` ] );
				} else {
					window.WPRecipeMaker.manager.loadRecipeDataFromAPI( idToGetDataFor ).then( ( result ) => {
						resolveData( result );
					});
				}
			});

			getRecipeData.then( ( recipeData ) => {
				// Create recipe object from recipe data.
				let recipe = false;

				if ( recipeData ) {
					recipe = window.WPRecipeMaker.manager.loadRecipeObject( id, recipeData );
				}

				window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ] = recipe;
				resolveRecipe( recipe );
			});
		});
	},
	getPublicPostCandidates: ( id ) => {
		let candidates = [];

		// Context the server already validated for this recipe, if any.
		if ( window.hasOwnProperty( 'wprm_recipes' ) && window.wprm_recipes.hasOwnProperty( `recipe-${id}` ) ) {
			const context = window.wprm_recipes[ `recipe-${id}` ].public_render_context;

			if ( context && context.wprm_public_post ) {
				candidates.push( parseInt( context.wprm_public_post ) );
			}
		}

		// Posts rendering this recipe, also for cards added through AJAX. The server validates these.
		const containers = document.querySelectorAll( `[data-recipe-id="${ id }"][data-wprm-public-post]` );
		for ( let container of containers ) {
			candidates.push( parseInt( container.dataset.wprmPublicPost ) );
		}

		// Try each distinct rendering post so stale candidates cannot hide a later accessible instance.
		return [ ...new Set( candidates.filter( ( candidate ) => 0 < candidate ) ) ];
	},
	loadRecipeDataFromAPI: ( id ) => {
		let headers = {
			'Accept': 'application/json',
			'Content-Type': 'application/json',
		};

		// Only require nonce when logged in to prevent caching problems for regular visitors.
		if ( 0 < parseInt( wprm_public.user ) ) {
			headers['X-WP-Nonce'] = wprm_public.api_nonce;
		}

		const candidates = window.WPRecipeMaker.manager.getPublicPostCandidates( id );

		const load = ( publicPost ) => {
			let body = {
				format: 'frontend',
			};

			if ( publicPost ) {
				body.public_render_context = {
					wprm_public_post: publicPost,
				};
			}

			return fetch(`${wprm_public.endpoints.manage}/recipe/${ id }`, {
				method: 'POST',
				headers,
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			}).then( (response) => {
				if ( response.ok ) {
					return response.json();
				}
				return false;
			});
		};

		// Without a candidate, keep the single ordinary request. Otherwise try the next candidate when one is rejected.
		const loadFrom = ( index ) => {
			return load( candidates[ index ] ).then( ( result ) => {
				if ( ! result && index + 1 < candidates.length ) {
					return loadFrom( index + 1 );
				}
				return result;
			});
		};

		return loadFrom( 0 );
	},
	loadRecipeObject: ( id, data ) => {
		let recipe = {
			id,
			data,
			// Functions to be overwritten. Define here to prevent errors.
			setServings: ( ...args ) => {},
			setAdvancedServings: ( ...args ) => {},
			setUnitSystem: ( ...args ) => {},
			addRating: ( ...args ) => {},
		}

		// Check if premium is active and should add functionality.
		if ( window.WPRecipeMaker.hasOwnProperty( 'managerPremium' ) ) {
			recipe = window.WPRecipeMaker.managerPremium.loadRecipeObject( id, recipe );
		}

		return recipe;
	},
	triggerChangeEvent: ( id, type ) => {
		document.dispatchEvent( new CustomEvent( 'wprm-recipe-change', { detail: { id, type } } ) );
	},
	changeRecipeData: ( id, data ) => {
		if ( window.WPRecipeMaker.manager.recipes.hasOwnProperty( `recipe-${id}` ) ) {
			window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ].data = {
				...window.WPRecipeMaker.manager.recipes[ `recipe-${id}` ].data,
				...data,
			};

			// Raw data change event.
			document.dispatchEvent( new CustomEvent( 'wprm-recipe-change-data', { detail: { id } } ) );
		}
	},
};

ready(() => {
	window.WPRecipeMaker.manager.init();
});

function ready( fn ) {
    if (document.readyState != 'loading'){
        fn();
    } else {
        document.addEventListener('DOMContentLoaded', fn);
    }
}