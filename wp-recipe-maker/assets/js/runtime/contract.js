/** Stable v1 boundary. Never expose Webpack IDs or runtime helpers here. */
function startupState( family ) {
    window.WPRMRuntime = window.WPRMRuntime || {};
    const runtime = window.WPRMRuntime.v1 = window.WPRMRuntime.v1 || {};
    runtime.startup = runtime.startup || {};
    runtime.startup[ family ] = runtime.startup[ family ] || { entries: {}, pending: [] };
    return runtime.startup[ family ];
}

function getRuntime( family ) {
    const runtime = window.WPRMRuntime && window.WPRMRuntime.v1;
    return runtime && runtime[ family ];
}

function reportUnavailable( family ) {
    const state = startupState( family );
    if ( state.reported ) { return; }
    state.reported = true;
    console.error( `WP Recipe Maker: the ${ family } runtime is missing. Refresh the page after updating WP Recipe Maker.` );
    window.dispatchEvent( new CustomEvent( 'wprm:runtime-unavailable', { detail: { version: 1, family } } ) );
}

export function requireRuntime( family ) {
    const runtime = getRuntime( family );
    if ( runtime ) { return runtime; }
    reportUnavailable( family );
    return null;
}

export function startWithRuntime( family, entry, start ) {
    const state = startupState( family );
    // State lives in v1 so separately compiled Premium entries share the queue.
    if ( state.entries[ entry ] ) { return; }
    state.entries[ entry ] = true;
    if ( getRuntime( family ) && state.ready !== false ) {
        start();
        return;
    }
    state.pending.push( start );
    if ( state.cancelDiagnostic ) { return; }

    let timer;
    const diagnose = () => {
        // Optimizers can delay Free beyond DOMContentLoaded or even window.load.
        // Diagnose a prolonged wait, but retain the queue for eventual recovery.
        timer = setTimeout( () => {
            if ( state.pending.length ) { reportUnavailable( family ); }
        }, 10000 );
    };
    state.cancelDiagnostic = () => {
        window.removeEventListener( 'load', diagnose );
        clearTimeout( timer );
        delete state.cancelDiagnostic;
    };
    if ( document.readyState === 'complete' ) {
        diagnose();
    } else {
        window.addEventListener( 'load', diagnose, { once: true } );
    }
}

export function beginRuntime( family ) {
    startupState( family ).ready = false;
}

export function finishRuntime( family ) {
    if ( ! getRuntime( family ) ) { return; }
    const state = startupState( family );
    state.ready = true;
    if ( state.cancelDiagnostic ) { state.cancelDiagnostic(); }
    const pending = state.pending;
    state.pending = [];
    for ( const start of pending ) {
        try {
            start();
        } catch ( error ) {
            // One broken entry must not prevent other queued entries from starting.
            console.error( 'WP Recipe Maker: a queued entry failed to start.', error );
        }
    }
}
