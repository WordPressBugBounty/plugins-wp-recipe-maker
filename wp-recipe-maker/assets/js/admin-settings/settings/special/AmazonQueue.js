import React, { useEffect, useState } from 'react';
import PropTypes from 'prop-types';

import ApiWrapper from '../../../shared/ApiWrapper';

const getQueueEndpoint = () => {
    if ( 'object' === typeof window.wprmp_admin && window.wprmp_admin.endpoints && window.wprmp_admin.endpoints.amazon ) {
        return `${window.wprmp_admin.endpoints.amazon}/queue`;
    }

    return false;
};

const formatDateTime = (timestamp) => {
    timestamp = parseInt(timestamp);

    if ( ! timestamp ) {
        return 'Unknown';
    }

    return new Date(timestamp * 1000).toLocaleString();
};

const AmazonQueue = (props) => {
    const [status, setStatus] = useState(false);
    const [loading, setLoading] = useState(true);
    const [busyAction, setBusyAction] = useState(false);

    const endpoint = getQueueEndpoint();

    useEffect(() => {
        refreshStatus();
    }, []);

    const refreshStatus = () => {
        if ( ! endpoint ) {
            setLoading(false);
            return;
        }

        setLoading(true);

        ApiWrapper.call(endpoint, 'GET').then((data) => {
            if ( data ) {
                setStatus(data);
            }

            setLoading(false);
        });
    };

    const canManageQueue = () => {
        if ( props.settingsChanged ) {
            alert('Please save or cancel your settings changes before managing the Amazon queue.');
            return false;
        }

        return true;
    };

    const runAction = (action, url, method = 'POST') => {
        if ( ! canManageQueue() ) {
            return;
        }

        setBusyAction(action);

        ApiWrapper.call(url, method).then((data) => {
            if ( data ) {
                setStatus(data);
            }

            setBusyAction(false);
            setLoading(false);
        });
    };

    const runDueUpdates = () => {
        runAction('run', `${endpoint}/run`);
    };

    const resumeUpdates = () => {
        runAction('resume', `${endpoint}/resume`);
    };

    const clearQueue = () => {
        if ( ! confirm('Clear all queued Amazon product update batches?') ) {
            return;
        }

        runAction('clear', endpoint, 'DELETE');
    };

    if ( ! endpoint ) {
        return (
            <div className="wprm-setting-amazon-queue">
                Amazon queue controls require WP Recipe Maker Premium.
            </div>
        );
    }

    if ( loading ) {
        return (
            <div className="wprm-setting-amazon-queue">
                Loading Amazon queue status...
            </div>
        );
    }

    const hasStatus = !! status;
    const lastError = hasStatus && status.last_error && status.last_error.code ? status.last_error : false;
    const canRunUpdates = hasStatus && status.has_credentials && ! status.paused && ! status.cooling_down && ! status.active;
    const canResume = hasStatus && status.has_credentials && ( status.paused || status.cooling_down || lastError );

    return (
        <div className="wprm-setting-amazon-queue">
            {
                hasStatus
                ?
                <div className="wprm-setting-amazon-queue-status">
                    <div><strong>Credentials:</strong> { status.has_credentials ? 'Configured' : 'Missing' }</div>
                    <div><strong>Queue:</strong> { status.active ? 'Active' : 'Idle' } ({ status.queued_products } products in { status.queued_batches } batches)</div>
                    {
                        status.paused
                        ?
                        <div><strong>Paused:</strong> { status.paused_reason ? status.paused_reason : 'manual' } since { formatDateTime(status.paused_at) }</div>
                        :
                        null
                    }
                    {
                        status.cooling_down
                        ?
                        <div><strong>Cooling down until:</strong> { formatDateTime(status.cooldown_until) }</div>
                        :
                        null
                    }
                    {
                        lastError
                        ?
                        <div><strong>Last error:</strong> { lastError.code }{ lastError.message ? ` - ${lastError.message}` : '' }</div>
                        :
                        null
                    }
                </div>
                :
                <div className="wprm-setting-amazon-queue-status">
                    Unable to load Amazon queue status.
                </div>
            }
            <div className="wprm-setting-amazon-queue-actions">
                <button
                    className="button button-primary button-compact"
                    disabled={!! busyAction || ! canRunUpdates}
                    onClick={(e) => {
                        e.preventDefault();
                        runDueUpdates();
                    }}
                >{ 'run' === busyAction ? 'Working...' : 'Run Due Updates Now' }</button>
                <button
                    className="button button-secondary button-compact"
                    disabled={!! busyAction || ! canResume}
                    onClick={(e) => {
                        e.preventDefault();
                        resumeUpdates();
                    }}
                >{ 'resume' === busyAction ? 'Working...' : 'Resume Updates' }</button>
                <button
                    className="button button-secondary button-compact"
                    disabled={!! busyAction}
                    onClick={(e) => {
                        e.preventDefault();
                        clearQueue();
                    }}
                >{ 'clear' === busyAction ? 'Working...' : 'Clear Queue' }</button>
                <button
                    className="button button-secondary button-compact"
                    disabled={!! busyAction}
                    onClick={(e) => {
                        e.preventDefault();
                        refreshStatus();
                    }}
                >Refresh</button>
            </div>
        </div>
    );
}

AmazonQueue.propTypes = {
    setting: PropTypes.object.isRequired,
    settings: PropTypes.object.isRequired,
    settingsChanged: PropTypes.bool.isRequired,
}

export default AmazonQueue;
