import React, { useEffect, useRef, useState } from 'react';
import PropTypes from 'prop-types';
import { __wprm } from 'Shared/Translations';

const kitchenEndpoint = `${wprm_admin.endpoints.app}/kitchen`;

const getNonce = () => {
    if ( 'object' === typeof window.wpApiSettings && window.wpApiSettings.nonce ) {
        return window.wpApiSettings.nonce;
    }

    return wprm_admin.api_nonce;
};

const callKitchen = async (path = '', method = 'GET') => {
    const headers = {
        'X-WP-Nonce': getNonce(),
        'Accept': 'application/json',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
    };
    const args = {
        method,
        headers,
        credentials: 'same-origin',
    };

    if ( 'DELETE' === method ) {
        args.method = 'POST';
        headers['X-HTTP-Method-Override'] = 'DELETE';
    }

    const response = await fetch(`${kitchenEndpoint}${path}`, args);
    let body = {};

    try {
        body = await response.json();
    } catch (error) {
        body = {};
    }

    if ( ! response.ok ) {
        const requestError = new Error(body.message || __wprm( 'WPRM Kitchen could not complete the request.' ));
        requestError.code = body.code || 'wprm_kitchen_unknown_error';
        throw requestError;
    }

    return body;
};

const KitchenConnection = (props) => {
    const [status, setStatus] = useState(false);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const completionStarted = useRef(false);

    const updateStatus = (data) => {
        setStatus(data);
        setLoading(false);
        if ( props.onStatusChange ) {
            props.onStatusChange(data);
        }
    };

    const handleError = (error) => {
        setMessage(error && error.message ? error.message : __wprm( 'WPRM Kitchen could not complete the request.' ));
        setBusy(false);
        setLoading(false);
    };

    const refreshStatus = () => {
        setLoading(true);
        callKitchen().then(updateStatus).catch(handleError);
    };

    useEffect(() => {
        refreshStatus();
    }, []);

    useEffect(() => {
        if ( status && 'complete' === status.action_required && ! completionStarted.current ) {
            completionStarted.current = true;
            setBusy(true);
            setMessage(__wprm( 'Finishing the secure connection...' ));
            callKitchen('/complete', 'POST').then((data) => {
                setMessage(__wprm( 'WPRM Kitchen is connected.' ));
                setBusy(false);
                updateStatus(data);
            }).catch(handleError);
        }
    }, [status]);

    const ensureSettingsSaved = () => {
        if ( props.settingsChanged ) {
            alert(__wprm( 'Please save or cancel your settings changes before managing the WPRM Kitchen connection.' ));
            return false;
        }

        return true;
    };

    const connect = () => {
        if ( ! ensureSettingsSaved() ) {
            return;
        }

        setBusy(true);
        setMessage(__wprm( 'Creating a secure WPRM Kitchen pairing...' ));
        callKitchen('/connect', 'POST').then((data) => {
            updateStatus(data);
            if ( data.authorize_url ) {
                window.location.assign(data.authorize_url);
            } else {
                setBusy(false);
                setMessage(__wprm( 'WPRM Kitchen did not return a login URL. Please try again.' ));
            }
        }).catch(handleError);
    };

    const approve = () => {
        if ( ! ensureSettingsSaved() ) {
            return;
        }

        setBusy(true);
        setMessage(__wprm( 'Approving and exchanging credentials securely...' ));
        callKitchen('/approve', 'POST').then((data) => {
            setBusy(false);
            setMessage(__wprm( 'WPRM Kitchen is connected.' ));
            updateStatus(data);
            if ( data.kitchen_return_url ) {
                window.location.assign(data.kitchen_return_url);
            }
        }).catch(handleError);
    };

    const cancel = () => {
        setBusy(true);
        callKitchen('/cancel', 'POST').then((data) => {
            setBusy(false);
            setMessage(__wprm( 'The pending pairing was cancelled.' ));
            updateStatus(data);
        }).catch(handleError);
    };

    const testConnection = () => {
        setBusy(true);
        setMessage(__wprm( 'Testing the signed connection...' ));
        callKitchen('/test', 'POST').then((data) => {
            setBusy(false);
            setMessage(data.test && data.test.ok ? __wprm( 'The signed WPRM Kitchen connection is working.' ) : __wprm( 'The connection test completed.' ));
            updateStatus(data);
        }).catch(handleError);
    };

    const disconnect = () => {
        if ( ! ensureSettingsSaved() || ! confirm(__wprm( 'Disconnect WPRM Kitchen? Its site access will be revoked immediately.' )) ) {
            return;
        }

        setBusy(true);
        setMessage(__wprm( 'Revoking the WPRM Kitchen connection...' ));
        callKitchen('', 'DELETE').then((data) => {
            setBusy(false);
            setMessage(data.warning ? __wprm( 'Disconnected locally. %s' ).replace( /%(?:1\$)?s/g, () => data.warning.message ) : __wprm( 'WPRM Kitchen was disconnected.' ));
            updateStatus(data);
        }).catch(handleError);
    };

    if ( loading ) {
        return <div className="wprm-setting-kitchen-connection">{ __wprm( 'Loading WPRM Kitchen connection...' ) }</div>;
    }

    const state = status ? status.state : 'error';
    const error = status && status.error ? status.error.message : '';

    return (
        <div className="wprm-setting-kitchen-connection">
            <div className={`wprm-setting-kitchen-status wprm-setting-kitchen-status-${state}`}>
                {
                    'connected' === state
                    ?
                    <React.Fragment>
                        <strong>{ __wprm( 'Connected' ) }</strong>
                        <span>
                            {status.connection.workspace_name ? __wprm( 'Workspace: %s' ).replace( /%(?:1\$)?s/g, () => status.connection.workspace_name ) : __wprm( 'WPRM Kitchen workspace connected' )}
                        </span>
                        {status.connection.connected_at ? <span>{ __wprm( 'Connected: %s' ).replace( /%(?:1\$)?s/g, () => status.connection.connected_at ) }</span> : null}
                    </React.Fragment>
                    : null
                }
                {
                    'awaiting_kitchen' === state
                    ?
                    <React.Fragment>
                        <strong>{ __wprm( 'Waiting for WPRM Kitchen approval' ) }</strong>
                        <span>{ __wprm( 'Log in and choose a workspace to continue. This pairing expires shortly.' ) }</span>
                    </React.Fragment>
                    : null
                }
                {
                    'awaiting_approval' === state
                    ?
                    <React.Fragment>
                        <strong>{ __wprm( 'Approval required' ) }</strong>
                        <span>{ __wprm( 'WPRM Kitchen is requesting access to this site’s recipes, templates and other supported features. Approving also syncs daily aggregate analytics counts to Kitchen.' ) }</span>
                    </React.Fragment>
                    : null
                }
                {
                    'callback_received' === state
                    ?
                    <React.Fragment>
                        <strong>{ __wprm( 'Finishing connection' ) }</strong>
                        <span>{ __wprm( 'The one-time Kitchen approval was received and is being exchanged securely.' ) }</span>
                    </React.Fragment>
                    : null
                }
                {
                    'error' === state
                    ?
                    <React.Fragment>
                        <strong>{ __wprm( 'Connection error' ) }</strong>
                        <span>{error || message || __wprm( 'The WPRM Kitchen connection could not be completed.' )}</span>
                    </React.Fragment>
                    : null
                }
                {
                    'disconnected' === state
                    ?
                    <React.Fragment>
                        <strong>{ __wprm( 'Not connected' ) }</strong>
                        <span>{ __wprm( 'Connect this WordPress site to a WPRM Kitchen workspace.' ) }</span>
                    </React.Fragment>
                    : null
                }
            </div>
            {
                status && false === status.premium_active
                ?
                <div className="wprm-setting-kitchen-notice">
                    { __wprm( 'Connection management is available, but recipe and analytics access requires WP Recipe Maker Premium.' ) }
                </div>
                : null
            }
            {
                'connected' !== state
                ?
                <div className="wprm-setting-kitchen-notice">
                    { __wprm( 'Connecting grants WPRM Kitchen access to recipes, templates and other supported features, and syncs daily analytics counts per recipe, post and action. Individual events, visitor identifiers, IP addresses and user agents stay in WordPress.' ) }
                </div>
                :
                <div className="wprm-setting-kitchen-notice">
                    { __wprm( 'This connection grants WPRM Kitchen access to all supported features. Disconnect to revoke that access and stop syncing.' ) }
                </div>
            }
            {message && message !== error ? <div className="wprm-setting-kitchen-message">{message}</div> : null}
            <div className="wprm-setting-kitchen-actions">
                {
                    'disconnected' === state || 'error' === state
                    ?
                    <button className="button button-primary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); connect(); }}>
                        {busy ? __wprm( 'Working...' ) : __wprm( 'Connect to WPRM Kitchen' )}
                    </button>
                    : null
                }
                {
                    'awaiting_kitchen' === state && status.authorize_url
                    ?
                    <a className="button button-primary button-compact" href={status.authorize_url}>{ __wprm( 'Continue in WPRM Kitchen' ) }</a>
                    : null
                }
                {
                    'awaiting_approval' === state
                    ?
                    <button className="button button-primary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); approve(); }}>
                        {busy ? __wprm( 'Working...' ) : __wprm( 'Approve Connection' )}
                    </button>
                    : null
                }
                {
                    [ 'awaiting_kitchen', 'awaiting_approval', 'callback_received' ].includes(state)
                    ?
                    <button className="button button-secondary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); cancel(); }}>{ __wprm( 'Cancel' ) }</button>
                    : null
                }
                {
                    'connected' === state
                    ?
                    <React.Fragment>
                        <button className="button button-secondary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); testConnection(); }}>{ __wprm( 'Test Connection' ) }</button>
                        <button className="button button-secondary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); disconnect(); }}>{ __wprm( 'Disconnect' ) }</button>
                    </React.Fragment>
                    : null
                }
            </div>
        </div>
    );
};

KitchenConnection.propTypes = {
    settingsChanged: PropTypes.bool.isRequired,
    onStatusChange: PropTypes.func,
};

export default KitchenConnection;
