import React, { useEffect, useState } from 'react';
import PropTypes from 'prop-types';
import { QRCodeSVG } from 'qrcode.react';

import ApiWrapper from '../../../shared/ApiWrapper';

const tokensEndpoint = `${wprm_admin.endpoints.app}/tokens`;

const AppAccessTokens = (props) => {
    const [status, setStatus] = useState(false);
    const [newToken, setNewToken] = useState('');
    const [newTokenName, setNewTokenName] = useState('');
    const [deviceName, setDeviceName] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        refreshStatus();
    }, []);

    const refreshStatus = () => {
        setLoading(true);

        ApiWrapper.call(tokensEndpoint, 'GET').then((data) => {
            if ( data ) {
                setStatus(data);
            }

            setLoading(false);
        });
    };

    const generateToken = () => {
        if ( props.settingsChanged ) {
            alert('Please save or cancel your settings changes before managing companion app tokens.');
            return;
        }

        if ( status && false === status.premium_active ) {
            alert('The companion app requires WP Recipe Maker Premium.');
            return;
        }

        setBusy(true);
        setNewToken('');
        setCopied(false);

        ApiWrapper.call(tokensEndpoint, 'POST', { name: deviceName }).then((data) => {
            if ( data && data.token ) {
                setStatus(data);
                setNewToken(data.token);
                setNewTokenName(deviceName);
                setDeviceName('');
            }

            setBusy(false);
        });
    };

    const revokeToken = (token) => {
        if ( props.settingsChanged ) {
            alert('Please save or cancel your settings changes before managing companion app tokens.');
            return;
        }

        if ( ! confirm(`Revoking the "${token.name}" token will immediately stop that device from accessing your recipes. Continue?`) ) {
            return;
        }

        setBusy(true);

        ApiWrapper.call(`${tokensEndpoint}/${token.id}`, 'DELETE').then((data) => {
            if ( data ) {
                setStatus(data);
            }

            setNewToken('');
            setCopied(false);
            setBusy(false);
        });
    };

    const copyToken = () => {
        if ( ! newToken ) {
            return;
        }

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText(newToken).then(() => setCopied(true));
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = newToken;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        setCopied(true);
    };

    if ( loading ) {
        return (
            <div className="wprm-setting-app-access-tokens">
                Loading companion app tokens...
            </div>
        );
    }

    const premiumActive = status && status.premium_active;
    const tokens = status && status.tokens ? status.tokens : [];

    const qrPayload = newToken ? JSON.stringify({
        v: 1,
        api: status.api_url,
        site: status.site_url,
        name: status.site_name,
        token: newToken,
    }) : '';

    return (
        <div className="wprm-setting-app-access-tokens">
            {
                ! premiumActive
                ?
                <div className="wprm-setting-app-access-tokens-notice">
                    The companion app requires WP Recipe Maker Premium.
                </div>
                :
                null
            }
            {
                tokens.length
                ?
                <table className="wprm-setting-app-access-tokens-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Created</th>
                            <th>Last used</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {
                            tokens.map((token) => (
                                <tr key={token.id}>
                                    <td>{token.name}</td>
                                    <td>{token.created_at ? token.created_at : 'Unknown'}</td>
                                    <td>{token.last_used_at ? token.last_used_at : 'Never'}</td>
                                    <td>
                                        <button
                                            className="button button-secondary button-compact"
                                            disabled={busy}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                revokeToken(token);
                                            }}
                                        >Revoke</button>
                                    </td>
                                </tr>
                            ))
                        }
                    </tbody>
                </table>
                :
                <div className="wprm-setting-app-access-tokens-status">
                    No devices connected yet. Add a device to get started.
                </div>
            }
            {
                newToken
                ?
                <div className="wprm-setting-app-access-tokens-generated">
                    <div className="wprm-setting-app-access-tokens-notice">
                        Scan this QR code with the companion app{ newTokenName ? ` to connect "${newTokenName}"` : '' }, or copy the token. It will not be shown again after you leave this page.
                    </div>
                    <div className="wprm-setting-app-access-tokens-qr">
                        <QRCodeSVG
                            value={qrPayload}
                            size={200}
                            marginSize={2}
                        />
                    </div>
                    <textarea
                        className="wprm-setting-input"
                        readOnly
                        rows="3"
                        value={newToken}
                    />
                    <button
                        className="button button-secondary button-compact"
                        disabled={busy}
                        onClick={(e) => {
                            e.preventDefault();
                            copyToken();
                        }}
                    >{ copied ? 'Copied' : 'Copy Token' }</button>
                </div>
                :
                null
            }
            <div className="wprm-setting-app-access-tokens-actions">
                <input
                    className="wprm-setting-input"
                    type="text"
                    placeholder="Device name, e.g. Brecht's iPhone"
                    value={deviceName}
                    disabled={busy || ! premiumActive}
                    onChange={(e) => setDeviceName(e.target.value)}
                />
                <button
                    className="button button-primary button-compact"
                    disabled={busy || ! premiumActive}
                    onClick={(e) => {
                        e.preventDefault();
                        generateToken();
                    }}
                >{ busy ? 'Working...' : 'Add Device' }</button>
            </div>
        </div>
    );
}

AppAccessTokens.propTypes = {
    setting: PropTypes.object.isRequired,
    settings: PropTypes.object.isRequired,
    settingsChanged: PropTypes.bool.isRequired,
}

export default AppAccessTokens;
