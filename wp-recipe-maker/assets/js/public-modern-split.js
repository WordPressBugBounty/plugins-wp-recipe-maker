import './public/public-path';

window.WPRecipeMaker = typeof window.WPRecipeMaker === 'undefined' ? {} : window.WPRecipeMaker;

import './public/analytics';
import './public/comment-rating';
import './public/manager';
import './public/modal';
import './public/print';
import './public/recipe';
import './public/smooth-scroll';
import './public/temperature';
import './public/tooltip';

const featurePromises = {};

const ready = ( fn ) => {
	if ( document.readyState !== 'loading' ) {
		fn();
	} else {
		document.addEventListener( 'DOMContentLoaded', fn );
	}
};

const hasElement = ( selector, root = document ) => {
	if ( ! root ) {
		return false;
	}

	if ( root !== document && root.matches && root.matches( selector ) ) {
		return true;
	}

	return !! ( root.querySelector && root.querySelector( selector ) );
};

const loadFeature = ( key, importer ) => {
	if ( ! featurePromises[ key ] ) {
		featurePromises[ key ] = importer().catch( ( error ) => {
			delete featurePromises[ key ];
			console.error( `WP Recipe Maker could not load the ${ key } feature.`, error );
		} );
	}

	return featurePromises[ key ];
};

const loaders = {
	commentRating: () => Promise.resolve(),
	cookpal: () => loadFeature( 'cookpal', () => import( /* webpackChunkName: "public-feature-cookpal" */ './public/cookpal' ) ),
	debug: () => loadFeature( 'debug', () => import( /* webpackChunkName: "public-feature-debug" */ './public/debug' ) ),
	expandable: () => loadFeature( 'expandable', () => import( /* webpackChunkName: "public-feature-expandable" */ './public/expandable' ) ),
	grow: () => loadFeature( 'grow', () => import( /* webpackChunkName: "public-feature-grow" */ './public/grow' ) ),
	instacart: () => loadFeature( 'instacart', () => import( /* webpackChunkName: "public-feature-instacart" */ './public/instacart' ) ),
	jump: () => Promise.resolve(),
	jumpToSection: () => loadFeature( 'jumpToSection', () => import( /* webpackChunkName: "public-feature-jump-to-section" */ './public/jump-to-section' ) ),
	media: () => loadFeature( 'media', () => import( /* webpackChunkName: "public-feature-media-toggle" */ './public/media-toggle' ) ),
	modal: () => Promise.resolve(),
	myShoppingHelp: () => loadFeature( 'myShoppingHelp', () => import( /* webpackChunkName: "public-feature-my-shopping-help" */ './public/my-shopping-help' ) ),
	pinterest: () => loadFeature( 'pinterest', () => import( /* webpackChunkName: "public-feature-pinterest" */ './public/pinterest' ) ),
	print: () => Promise.resolve(),
	slickstream: () => loadFeature( 'slickstream', () => import( /* webpackChunkName: "public-feature-slickstream" */ './public/slickstream' ) ),
	tooltip: () => Promise.resolve(),
	video: () => loadFeature( 'video', () => import( /* webpackChunkName: "public-feature-video" */ './public/video' ) ),
};

const detectFeatures = ( root = document ) => {
	if ( wprm_public.settings.features_comment_ratings || hasElement( '.comment-form-wprm-rating, .wprm-comment-ratings-container, .wprm-user-ratings-modal-stars', root ) ) {
		loaders.commentRating();
	}
	if ( hasElement( '#wp-admin-bar-wprm-debug, [id^="wp-admin-bar-wprm-debug-entry"]', root ) ) {
		loaders.debug();
	}
	if ( hasElement( '.wprm-expandable-button', root ) ) {
		loaders.expandable();
	}
	if ( hasElement( '.wprm-recipe-grow', root ) ) {
		loaders.grow();
	}
	if ( hasElement( '.wprm-recipe-shop-instacart', root ) ) {
		loaders.instacart();
	}
	if ( hasElement( '.wprm-recipe-jump, .wprm-recipe-jump-to-comments, .wprm-recipe-jump-video, .wprm-jump-smooth-scroll, .wprm-recipe-jump-to-section', root ) ) {
		loaders.jump();
	}
	if ( hasElement( '.wprm-recipe-jump-to-section-container-scroll', root ) ) {
		loaders.jumpToSection();
	}
	if ( hasElement( '.wprm-recipe-media-toggle, .wprm-media-toggle-checkbox', root ) ) {
		loaders.media();
	}
	if ( hasElement( '.wprm-popup-modal, [data-modal-uid]', root ) ) {
		loaders.modal();
	}
	if ( hasElement( '.wprm-recipe-my-shopping-help', root ) ) {
		loaders.myShoppingHelp();
	}
	if ( hasElement( '.wprm-recipe-cookpal', root ) ) {
		loaders.cookpal();
	}
	if ( hasElement( '.wprm-recipe-pin', root ) ) {
		loaders.pinterest();
	}
	if ( hasElement( '.wprm-recipe-print, .wprm-print-recipe-shortcode', root ) ) {
		loaders.print();
	}
	if ( hasElement( '.wprm-recipe-slickstream', root ) ) {
		loaders.slickstream();
	}
	if ( hasElement( '.wprm-tooltip', root ) ) {
		loaders.tooltip();
	}
	if ( wprm_public.settings.video_force_ratio && hasElement( '.wprm-recipe iframe, .wprm-recipe object, .wprm-recipe video, .wprm-recipe-video-container iframe, .wprm-recipe-video-container object, .wprm-recipe-video-container video', root ) ) {
		loaders.video();
	}
};

const pendingFeatureDetectionRoots = new Set();
let detectScheduled = false;
const scheduleFeatureDetection = ( root = document ) => {
	pendingFeatureDetectionRoots.add( root || document );

	if ( detectScheduled ) {
		return;
	}

	detectScheduled = true;
	window.setTimeout( () => {
		detectScheduled = false;

		const roots = Array.from( pendingFeatureDetectionRoots );
		pendingFeatureDetectionRoots.clear();

		for ( const detectionRoot of roots ) {
			detectFeatures( detectionRoot );
		}
	}, 0 );
};

const observeFeatureDom = () => {
	if ( ! window.MutationObserver || ! document.body ) {
		return;
	}

	const observer = new MutationObserver( ( mutations ) => {
		for ( const mutation of mutations ) {
			for ( const node of mutation.addedNodes ) {
				if ( node.nodeType === 1 ) {
					scheduleFeatureDetection( mutation.target );
					break;
				}
			}
		}
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true,
	} );
};

window.WPRecipeMaker.publicLoader = {
	detect: scheduleFeatureDetection,
	has: hasElement,
	load: ( key ) => loaders[ key ] ? loaders[ key ]() : Promise.resolve(),
};

ready( () => {
	detectFeatures();
	observeFeatureDom();
} );

document.addEventListener( 'wprmRecipeInit', () => {
	scheduleFeatureDetection();
} );
