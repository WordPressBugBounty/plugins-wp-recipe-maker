const modalEndpoint = wprm_admin.endpoints.modal;

import ApiWrapper from '../ApiWrapper';

const rateLimit = 500;

// Each editor owns its queue. Pending searches may be replaced, but a response
// always settles the promise belonging to the payload that produced it.
function createSuggestionsRequester() {
    let active = null;
    let pending = null;
    let timer = null;
    let startedAt = null;

    const runNext = () => {
        if ( active || ! pending ) { return; }

        const remaining = null === startedAt ? 0 : rateLimit - ( Date.now() - startedAt );
        if ( 0 < remaining ) {
            timer = setTimeout(() => {
                timer = null;
                runNext();
            }, remaining);
            return;
        }

        const request = pending;
        pending = null;
        active = request;
        startedAt = Date.now();

        const finish = () => {
            active = null;
            runNext();
        };

        // Also release the queue after a rejected request or synchronous error.
        Promise.resolve().then(() => request.cancelled ? false : ApiWrapper.call( `${modalEndpoint}/suggest`, 'POST', request.args )).then(data => {
            request.resolve( data );
            finish();
        }, error => {
            request.reject( error );
            finish();
        });
    };

    const cancel = () => {
        if ( null !== timer ) {
            clearTimeout( timer );
            timer = null;
        }
        if ( pending ) {
            pending.resolve( false );
            pending = null;
        }
        // The transport can finish, but its caller no longer needs the result.
        if ( active ) {
            active.cancelled = true;
            active.resolve( false );
        }
    };

    return {
        getSuggestions(args) {
            if ( pending ) { pending.resolve( false ); }
            if ( null !== timer ) {
                clearTimeout( timer );
                timer = null;
            }

            return new Promise((resolve, reject) => {
                pending = { args: { ...args }, resolve, reject };
                runNext();
            });
        },
        cancel,
    };
}

const suggestionsRequester = createSuggestionsRequester();

export default {
    createSuggestionsRequester,
    getSuggestions(args) {
        return suggestionsRequester.getSuggestions( args );
    },
    getIngredientUnitConnector(unit, create = false) {
        return ApiWrapper.call( `${modalEndpoint}/ingredient-unit/connector`, 'POST', {
            unit,
            create,
        } );
    },
};
