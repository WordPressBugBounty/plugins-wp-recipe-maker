/**
 * Adapter for the published 10.8.x Webpack JSONP protocol.
 * All application modules/chunks remain in the installed paid plugin. The only
 * initial factories are audited adapters to Free's versioned public interface.
 */
export function installLegacyRuntime( global, config, factories ) {
    const modules = { ...factories };
    const cache = Object.create( null );
    const chunks = Object.create( null );
    const deferred = [];
    const requests = Object.create( null );
    for ( const id of config.loadedChunks || [] ) { chunks[ id ] = 0; }

    function requireModule( id ) {
        if ( cache[ id ] ) { return cache[ id ].exports; }
        if ( ! Object.prototype.hasOwnProperty.call( modules, id ) ) {
            throw new Error( `WPRM legacy dependency ${ id } is unavailable (${ config.profile }).` );
        }
        const module = cache[ id ] = { id, loaded: false, exports: {} };
        try {
            modules[ id ].call( module.exports, module, module.exports, requireModule );
            module.loaded = true;
            return module.exports;
        } catch ( error ) {
            delete cache[ id ];
            throw error;
        }
    }
    requireModule.m = modules;
    requireModule.c = cache;
    requireModule.g = global;
    requireModule.o = ( object, key ) => Object.prototype.hasOwnProperty.call( object, key );
    requireModule.d = ( exports, definition ) => {
        for ( const key in definition ) {
            if ( requireModule.o( definition, key ) && ! requireModule.o( exports, key ) ) {
                Object.defineProperty( exports, key, { enumerable: true, get: definition[ key ] } );
            }
        }
    };
    requireModule.r = ( exports ) => {
        if ( typeof Symbol !== 'undefined' && Symbol.toStringTag ) {
            Object.defineProperty( exports, Symbol.toStringTag, { value: 'Module' } );
        }
        Object.defineProperty( exports, '__esModule', { value: true } );
    };
    requireModule.n = ( module ) => {
        const getter = module && module.__esModule ? () => module.default : () => module;
        requireModule.d( getter, { a: getter } );
        return getter;
    };
    requireModule.nmd = ( module ) => {
        module.paths = [];
        if ( ! module.children ) { module.children = []; }
        return module;
    };
    requireModule.hmd = ( module ) => {
        module = Object.create( module );
        if ( ! module.children ) { module.children = []; }
        Object.defineProperty( module, 'exports', { enumerable: true, set() { throw new Error( 'ES modules cannot assign module.exports.' ); } } );
        return module;
    };
    requireModule.t = function( value, mode ) {
        if ( mode & 1 ) { value = requireModule( value ); }
        if ( mode & 8 || typeof value === 'object' && value && ( mode & 4 && value.__esModule || mode & 16 && typeof value.then === 'function' ) ) { return value; }
        const namespace = Object.create( null );
        requireModule.r( namespace );
        const definition = { default: () => value };
        if ( mode & 2 && value && typeof value !== 'string' ) {
            for ( let object = value; object && object !== Object.prototype && object !== Function.prototype && object !== Array.prototype; object = Object.getPrototypeOf( object ) ) {
                for ( const key of Object.getOwnPropertyNames( object ) ) {
                    if ( key !== 'default' ) { definition[ key ] = () => value[ key ]; }
                }
            }
        }
        requireModule.d( namespace, definition );
        return namespace;
    };
    requireModule.p = config.assetsUrl;
    requireModule.u = ( id ) => {
        if ( ! requireModule.o( config.files, id ) ) { throw new Error( `Unknown WPRM legacy chunk ${ id }.` ); }
        return config.files[ id ];
    };
    requireModule.l = ( url, done ) => {
        if ( requests[ url ] ) { requests[ url ].push( done ); return; }
        requests[ url ] = [ done ];
        const script = global.document.createElement( 'script' );
        script.charset = 'utf-8';
        script.timeout = 120;
        if ( config.nonce ) { script.setAttribute( 'nonce', config.nonce ); }
        script.src = url;
        const finish = ( event ) => {
            clearTimeout( timer );
            script.onerror = script.onload = null;
            script.remove();
            const callbacks = requests[ url ];
            delete requests[ url ];
            for ( const callback of callbacks || [] ) { callback( event ); }
        };
        const timer = setTimeout( () => finish( { type: 'timeout', target: script } ), 120000 );
        script.onerror = script.onload = finish;
        global.document.head.appendChild( script );
    };
    requireModule.e = ( id ) => {
        if ( chunks[ id ] === 0 ) { return Promise.resolve(); }
        if ( chunks[ id ] ) { return chunks[ id ].promise; }
        let file;
        try { file = requireModule.u( id ); } catch ( error ) { return Promise.reject( error ); }
        const url = requireModule.p + file;
        const state = {};
        state.promise = new Promise( ( resolve, reject ) => { state.resolve = resolve; state.reject = reject; } );
        chunks[ id ] = state;
        requireModule.l( url, ( event ) => {
            if ( chunks[ id ] === 0 ) { return; }
            delete chunks[ id ];
            const error = new Error( `Loading WPRM legacy chunk ${ id } failed (${ event.type }: ${ url }).` );
            error.name = 'ChunkLoadError'; error.type = event.type === 'load' ? 'missing' : event.type; error.request = url;
            state.reject( error );
        } );
        return state.promise;
    };
    requireModule.O = ( result, ids, callback, priority = 0 ) => {
        if ( ids ) {
            deferred.push( { ids, callback, priority } );
            deferred.sort( ( a, b ) => a.priority - b.priority );
            return;
        }
        for ( let index = 0; index < deferred.length; ) {
            const item = deferred[ index ];
            if ( item.ids.every( ( id ) => chunks[ id ] === 0 ) ) {
                deferred.splice( index, 1 );
                const value = item.callback();
                if ( value !== undefined ) { result = value; }
            } else { index++; }
        }
        return result;
    };
    requireModule.O.j = ( id ) => chunks[ id ] === 0;

    const queue = global[ config.queue ] = global[ config.queue ] || [];
    const register = ( [ ids, newModules, runtime ] ) => {
        for ( const id in newModules ) {
            if ( requireModule.o( newModules, id ) ) { modules[ id ] = newModules[ id ]; }
        }
        const result = runtime && runtime( requireModule );
        for ( const id of ids ) {
            if ( chunks[ id ] && chunks[ id ].resolve ) { chunks[ id ].resolve(); }
            chunks[ id ] = 0;
        }
        return requireModule.O( result );
    };
    const pending = queue.slice();
    queue.push = register;
    for ( const data of pending ) { register( data ); }
    return requireModule;
}
