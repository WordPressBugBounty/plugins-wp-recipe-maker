/** Isolate the released print runtime and resolve its PDF inside its own package. */
export function installLegacyQueues( legacy ) {
    if ( ! legacy || window.WPRMLegacyPrintQueue ) { return; }
    window.WPRMLegacyPrintQueue = true;
    let printQueue;
    if ( legacy.printSharesQueue ) {
        // 10.8.0/1 print and public originally used the same JSONP global.
        let publicQueue = window.webpackChunkWPRecipeMaker || [];
        printQueue = [];
        const isPrintScript = () => {
            const script = document.currentScript;
            if ( ! script || ! script.src ) { return false; }
            const url = script.src.split( '?' )[0];
            return url === legacy.assetsUrl + 'print.js' || url === legacy.assetsUrl + legacy.pdf;
        };
        Object.defineProperty( window, 'webpackChunkWPRecipeMaker', {
            configurable: true,
            get: () => isPrintScript() ? printQueue : publicQueue,
            set: value => { if ( isPrintScript() ) { printQueue = value; } else { publicQueue = value; } },
        } );
    } else {
        printQueue = window.webpackChunkWPRecipeMakerPrint = window.webpackChunkWPRecipeMakerPrint || [];
    }
    // Replay inside the released print runtime before its entry starts. Its old
    // public-path setter assumes a literal plugin folder name. Keep both URL
    // components package-local, including relocated/CDN script URLs.
    printQueue.push( [ [ 'wprm-legacy-print-path' ], {}, require => {
        Object.defineProperty( require, 'p', { get: () => legacy.assetsUrl, set: () => {} } );
        require.u = id => {
            if ( ! Object.prototype.hasOwnProperty.call( legacy.files, id ) ) {
                throw new Error( `Unknown WPRM legacy print chunk ${ id }.` );
            }
            return legacy.files[ id ];
        };
    } ] );
}
