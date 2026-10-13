import tippy, { inlinePositioning } from 'tippy.js';
import { installLegacyQueues } from './legacy-queues';
const Tippy = { __esModule: true, default: tippy, inlinePositioning };
import animateScrollTo from 'animated-scroll-to';
import { __wprm } from '../shared/Translations';
import { installLegacyRuntime } from './legacy-loader';

window.WPRMRuntime = window.WPRMRuntime || {};
const runtime = window.WPRMRuntime.v1 = window.WPRMRuntime.v1 || {};
runtime.frontend = runtime.frontend || { Tippy, animateScrollTo };
const legacy = window.wprm_public && window.wprm_public.legacy_runtime;
if ( legacy && ! runtime.legacyPublic ) {
    runtime.legacyPublic = true;
    const vendors = runtime.frontend;
    installLegacyQueues( legacy );
    const factories = {
        4558: module => { module.exports = { Ay: vendors.Tippy.default, DX: vendors.Tippy.inlinePositioning }; },
        4181: module => { module.exports = { Ay: vendors.Tippy.default, DX: vendors.Tippy.inlinePositioning }; },
        5266: module => { module.exports = { A: vendors.animateScrollTo }; },
        942: module => { module.exports = { f: __wprm }; },
        2998: () => {},
    };
    // Both queues are needed: older inline embeds always use the combined entry.
    for ( const queue of [ 'webpackChunkWPRecipeMaker', 'webpackChunkWPRecipeMakerPublicSplit' ] ) {
        installLegacyRuntime( window, { ...legacy, queue }, factories );
    }
}
