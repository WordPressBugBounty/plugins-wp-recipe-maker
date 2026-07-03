import React, { useEffect, useState } from 'react';
import PropTypes from 'prop-types';

import ApiWrapper from '../../../shared/ApiWrapper';

const tokenEndpoint = `${wprm_admin.endpoints.integrations}/jupiter/token`;

const JupiterAccessToken = (props) => {
    const [status, setStatus] = useState(false);
    const [token, setToken] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        refreshStatus();
    }, []);

    const refreshStatus = () => {
        setLoading(true);

        ApiWrapper.call(tokenEndpoint, 'GET').then((data) => {
            if ( data ) {
                setStatus(data);
            }

            setLoading(false);
        });
    };

    const canManageToken = () => {
        if ( props.settingsChanged ) {
            alert('Please save or cancel your settings changes before managing the Jupiter access token.');
            return false;
        }

        if ( ! props.settings.integration_jupiter ) {
            alert('Activate Jupiter and save your settings before generating an access token.');
            return false;
        }

        if ( status && false === status.ingredient_links_available ) {
            alert('Ingredient links require WP Recipe Maker Premium.');
            return false;
        }

        return true;
    };

    const generateToken = () => {
        if ( ! canManageToken() ) {
            return;
        }

        if ( status && status.has_token && ! confirm('Regenerating the token will immediately stop the previous Jupiter token from working. Continue?') ) {
            return;
        }

        setBusy(true);
        setToken('');
        setCopied(false);

        ApiWrapper.call(tokenEndpoint, 'POST').then((data) => {
            if ( data ) {
                setStatus(data);
                setToken(data.token ? data.token : '');
            }

            setBusy(false);
        });
    };

    const revokeToken = () => {
        if ( props.settingsChanged ) {
            alert('Please save or cancel your settings changes before managing the Jupiter access token.');
            return;
        }

        if ( ! confirm('Revoking the token will immediately stop Jupiter from reading or updating ingredient links. Continue?') ) {
            return;
        }

        setBusy(true);

        ApiWrapper.call(tokenEndpoint, 'DELETE').then((data) => {
            if ( data ) {
                setStatus(data);
            }

            setToken('');
            setCopied(false);
            setBusy(false);
        });
    };

    const copyToken = () => {
        if ( ! token ) {
            return;
        }

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText(token).then(() => setCopied(true));
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = token;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        setCopied(true);
    };

    if ( loading ) {
        return (
            <div className="wprm-setting-jupiter-access-token">
                Loading Jupiter token status...
            </div>
        );
    }

    const hasToken = status && status.has_token;
    const ingredientLinksAvailable = status && status.ingredient_links_available;
    const active = props.settings.integration_jupiter;

    return (
        <div className="wprm-setting-jupiter-access-token">
            {
                ! ingredientLinksAvailable
                ?
                <div className="wprm-setting-jupiter-access-token-notice">
                    Ingredient link access requires WP Recipe Maker Premium.
                </div>
                :
                null
            }
            {
                ! active
                ?
                <div className="wprm-setting-jupiter-access-token-notice">
                    Activate Jupiter and save your settings before generating a token.
                </div>
                :
                null
            }
            <div className="wprm-setting-jupiter-access-token-status">
                <strong>Status:</strong> { hasToken ? 'Token generated' : 'No token generated' }
            </div>
            {
                hasToken
                ?
                <div className="wprm-setting-jupiter-access-token-meta">
                    <div><strong>Created:</strong> { status.created_at ? status.created_at : 'Unknown' }</div>
                    <div><strong>Last used:</strong> { status.last_used_at ? status.last_used_at : 'Never' }</div>
                </div>
                :
                null
            }
            {
                status && status.ingredient_links_endpoint
                ?
                <div className="wprm-setting-jupiter-access-token-endpoint">
                    <strong>Ingredient links endpoint:</strong>
                    <input
                        className="wprm-setting-input"
                        type="text"
                        readOnly
                        value={status.ingredient_links_endpoint}
                    />
                </div>
                :
                null
            }
            {
                token
                ?
                <div className="wprm-setting-jupiter-access-token-generated">
                    <div className="wprm-setting-jupiter-access-token-notice">
                        Copy this token now. It will not be shown again after you leave this page.
                    </div>
                    <textarea
                        className="wprm-setting-input"
                        readOnly
                        rows="3"
                        value={token}
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
            <div className="wprm-setting-jupiter-access-token-actions">
                <button
                    className="button button-primary button-compact"
                    disabled={busy || ! ingredientLinksAvailable}
                    onClick={(e) => {
                        e.preventDefault();
                        generateToken();
                    }}
                >{ busy ? 'Working...' : hasToken ? 'Regenerate Token' : 'Generate Token' }</button>
                {
                    hasToken
                    ?
                    <button
                        className="button button-secondary button-compact"
                        disabled={busy}
                        onClick={(e) => {
                            e.preventDefault();
                            revokeToken();
                        }}
                    >Revoke Token</button>
                    :
                    null
                }
            </div>
        </div>
    );
}

JupiterAccessToken.propTypes = {
    setting: PropTypes.object.isRequired,
    settings: PropTypes.object.isRequired,
    settingsChanged: PropTypes.bool.isRequired,
}

export default JupiterAccessToken;
