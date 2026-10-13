import React from 'react';
import ReactDOM from 'react-dom';
import * as ReactDOMClient from 'react-dom/client';
import * as JSXRuntime from 'react/jsx-runtime';
import * as JSXDevRuntime from 'react/jsx-dev-runtime';
import ApiWrapper from '../shared/ApiWrapper';
import { installLegacyRuntime } from './legacy-loader';

// Named v1 exports preserve package independence while sharing initialized vendors.
// Getters defer initialization; Webpack's package-local cache supplies the same
// instance to Free imports and Premium consumers. Keep these semantics stable.
export const adminVendors = {
    get Select() { return require( 'react-select' ); },
    get AsyncSelect() { return require( 'react-select/async' ); },
    get AsyncCreatableSelect() { return require( 'react-select/async-creatable' ); },
    get DragDrop() { return require( 'react-beautiful-dnd' ); },
    get Table() { return require( 'react-table' ); },
    get Tippy() { return require( 'tippy.js' ); },
    get ReactTippy() { return require( '@tippyjs/react' ); },
    get HtmlEntities() { return require( 'he' ); },
};

export function publishAdminRuntime( hooks ) {
    window.WPRMRuntime = window.WPRMRuntime || {};
    const runtime = window.WPRMRuntime.v1 = window.WPRMRuntime.v1 || {};
    if ( runtime.admin ) { return runtime.admin.hooks; }
    runtime.admin = { React, ReactDOM, ReactDOMClient, JSXRuntime, JSXDevRuntime, hooks, ApiWrapper, vendors: adminVendors };
    const legacy = window.wprm_admin && window.wprm_admin.legacy_runtime;
    if ( legacy ) {
        installLegacyRuntime( window, { ...legacy, queue: 'webpackChunkWPRecipeMakerAdmin' }, {
            10409: module => { module.exports = { A: ApiWrapper }; },
            22483: module => { module.exports = ReactDOM; },
            77810: module => { module.exports = React; },
        } );
    }
    return hooks;
}
