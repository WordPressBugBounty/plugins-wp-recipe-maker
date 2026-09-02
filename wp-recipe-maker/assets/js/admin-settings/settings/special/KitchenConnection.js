import React, { useEffect, useRef, useState } from 'react';
import PropTypes from 'prop-types';

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
        const requestError = new Error(body.message || 'WPRM Kitchen could not complete the request.');
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
        setMessage(error && error.message ? error.message : 'WPRM Kitchen could not complete the request.');
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
            setMessage('Finishing the secure connection...');
            callKitchen('/complete', 'POST').then((data) => {
                setMessage('WPRM Kitchen is connected.');
                setBusy(false);
                updateStatus(data);
            }).catch(handleError);
        }
    }, [status]);

    const ensureSettingsSaved = () => {
        if ( props.settingsChanged ) {
            alert('Please save or cancel your settings changes before managing the WPRM Kitchen connection.');
            return false;
        }

        return true;
    };

    const connect = () => {
        if ( ! ensureSettingsSaved() ) {
            return;
        }

        setBusy(true);
        setMessage('Creating a secure WPRM Kitchen pairing...');
        callKitchen('/connect', 'POST').then((data) => {
            updateStatus(data);
            if ( data.authorize_url ) {
                window.location.assign(data.authorize_url);
            } else {
                setBusy(false);
                setMessage('WPRM Kitchen did not return a login URL. Please try again.');
            }
        }).catch(handleError);
    };

    const approve = () => {
        if ( ! ensureSettingsSaved() ) {
            return;
        }

        setBusy(true);
        setMessage('Approving and exchanging credentials securely...');
        callKitchen('/approve', 'POST').then((data) => {
            setBusy(false);
            setMessage('WPRM Kitchen is connected.');
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
            setMessage('The pending pairing was cancelled.');
            updateStatus(data);
        }).catch(handleError);
    };

    const testConnection = () => {
        setBusy(true);
        setMessage('Testing the signed connection...');
        callKitchen('/test', 'POST').then((data) => {
            setBusy(false);
            setMessage(data.test && data.test.ok ? 'The signed WPRM Kitchen connection is working.' : 'The connection test completed.');
            updateStatus(data);
        }).catch(handleError);
    };

    const disconnect = () => {
        if ( ! ensureSettingsSaved() || ! confirm('Disconnect WPRM Kitchen? Its site access will be revoked immediately.') ) {
            return;
        }

        setBusy(true);
        setMessage('Revoking the WPRM Kitchen connection...');
        callKitchen('', 'DELETE').then((data) => {
            setBusy(false);
            setMessage(data.warning ? `Disconnected locally. ${data.warning.message}` : 'WPRM Kitchen was disconnected.');
            updateStatus(data);
        }).catch(handleError);
    };

    if ( loading ) {
        return <div className="wprm-setting-kitchen-connection">Loading WPRM Kitchen connection...</div>;
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
                        <strong>Connected</strong>
                        <span>
                            {status.connection.workspace_name ? `Workspace: ${status.connection.workspace_name}` : 'WPRM Kitchen workspace connected'}
                        </span>
                        {status.connection.connected_at ? <span>Connected: {status.connection.connected_at}</span> : null}
                    </React.Fragment>
                    : null
                }
                {
                    'awaiting_kitchen' === state
                    ?
                    <React.Fragment>
                        <strong>Waiting for WPRM Kitchen approval</strong>
                        <span>Log in and choose a workspace to continue. This pairing expires shortly.</span>
                    </React.Fragment>
                    : null
                }
                {
                    'awaiting_approval' === state
                    ?
                    <React.Fragment>
                        <strong>Approval required</strong>
                        <span>WPRM Kitchen is requesting access to recipes and analytics on this site.</span>
                    </React.Fragment>
                    : null
                }
                {
                    'callback_received' === state
                    ?
                    <React.Fragment>
                        <strong>Finishing connection</strong>
                        <span>The one-time Kitchen approval was received and is being exchanged securely.</span>
                    </React.Fragment>
                    : null
                }
                {
                    'error' === state
                    ?
                    <React.Fragment>
                        <strong>Connection error</strong>
                        <span>{error || message || 'The WPRM Kitchen connection could not be completed.'}</span>
                    </React.Fragment>
                    : null
                }
                {
                    'disconnected' === state
                    ?
                    <React.Fragment>
                        <strong>Not connected</strong>
                        <span>Connect this WordPress site to a WPRM Kitchen workspace.</span>
                    </React.Fragment>
                    : null
                }
            </div>
            {
                status && false === status.premium_active
                ?
                <div className="wprm-setting-kitchen-notice">
                    Connection management is available, but recipe and analytics access requires WP Recipe Maker Premium.
                </div>
                : null
            }
            {message && message !== error ? <div className="wprm-setting-kitchen-message">{message}</div> : null}
            <div className="wprm-setting-kitchen-actions">
                {
                    'disconnected' === state || 'error' === state
                    ?
                    <button className="button button-primary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); connect(); }}>
                        {busy ? 'Working...' : 'Connect to WPRM Kitchen'}
                    </button>
                    : null
                }
                {
                    'awaiting_kitchen' === state && status.authorize_url
                    ?
                    <a className="button button-primary button-compact" href={status.authorize_url}>Continue in WPRM Kitchen</a>
                    : null
                }
                {
                    'awaiting_approval' === state
                    ?
                    <button className="button button-primary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); approve(); }}>
                        {busy ? 'Working...' : 'Approve Connection'}
                    </button>
                    : null
                }
                {
                    [ 'awaiting_kitchen', 'awaiting_approval', 'callback_received' ].includes(state)
                    ?
                    <button className="button button-secondary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); cancel(); }}>Cancel</button>
                    : null
                }
                {
                    'connected' === state
                    ?
                    <React.Fragment>
                        <button className="button button-secondary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); testConnection(); }}>Test Connection</button>
                        <button className="button button-secondary button-compact" disabled={busy} onClick={(event) => { event.preventDefault(); disconnect(); }}>Disconnect</button>
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
