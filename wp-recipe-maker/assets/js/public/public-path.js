/* global __webpack_public_path__ */

const publicAssetsUrl = window.wprm_public && window.wprm_public.assets_url;

if ( publicAssetsUrl ) {
	__webpack_public_path__ = publicAssetsUrl;
}
