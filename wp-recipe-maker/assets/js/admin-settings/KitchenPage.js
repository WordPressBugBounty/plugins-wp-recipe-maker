import React, { useState } from 'react';

import KitchenConnection from './settings/special/KitchenConnection';

const KitchenPage = () => {
    const [status, setStatus] = useState(false);
    const connected = status && status.connected;
    const premiumActive = status && status.premium_active;

    return (
        <div className={`wprm-kitchen-page ${connected ? 'wprm-kitchen-page-connected' : 'wprm-kitchen-page-disconnected'}`}>
            <section className="wprm-kitchen-hero">
                <div className="wprm-kitchen-hero-content">
                    <span className="wprm-kitchen-eyebrow">WP Recipe Maker</span>
                    <h1>WPRM Kitchen</h1>
                    <p>
                        {
                            connected
                            ? 'This site is securely connected. Open Kitchen to work with your recipes and connect your other WordPress sites.'
                            : 'Bring your WP Recipe Maker sites together in one secure workspace and manage your recipes beyond WordPress.'
                        }
                    </p>
                    {
                        connected && status.kitchen_origin
                        ?
                        <a className="button button-primary" href={status.kitchen_origin} target="_blank" rel="noopener noreferrer">Open WPRM Kitchen</a>
                        : null
                    }
                </div>
                <div className="wprm-kitchen-hero-mark" aria-hidden="true">K</div>
            </section>

            {
                connected
                ?
                <section className="wprm-kitchen-overview">
                    <h2>Your Kitchen connection</h2>
                    <div className="wprm-kitchen-cards">
                        <div className="wprm-kitchen-card">
                            <strong>Connection</strong>
                            <span>Securely connected</span>
                            {status.connection && status.connection.connected_at ? <small>Since {status.connection.connected_at}</small> : null}
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>Site access</strong>
                            <span>{premiumActive ? 'Recipes and analytics available' : 'Connection only'}</span>
                            {! premiumActive ? <small>WP Recipe Maker Premium unlocks recipe and analytics access.</small> : null}
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>More sites</strong>
                            <span>Connect every site to the same Kitchen workspace.</span>
                        </div>
                    </div>
                </section>
                :
                <section className="wprm-kitchen-overview">
                    <h2>Why connect to WPRM Kitchen?</h2>
                    <div className="wprm-kitchen-cards">
                        <div className="wprm-kitchen-card">
                            <strong>One workspace</strong>
                            <span>Bring multiple WP Recipe Maker sites together under one Kitchen account.</span>
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>Simple access</strong>
                            <span>Connect once here, then manage access centrally from WPRM Kitchen.</span>
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>Secure by design</strong>
                            <span>No WordPress password is shared, and access can be revoked at any time.</span>
                        </div>
                    </div>
                </section>
            }

            <section className="wprm-kitchen-connection-panel">
                <div className="wprm-kitchen-connection-heading">
                    <h2>{connected ? 'Manage connection' : 'Connect this site'}</h2>
                    <p>{connected ? 'Test the connection or disconnect this site.' : 'You will be sent to WPRM Kitchen to log in and choose a workspace.'}</p>
                </div>
                <KitchenConnection settingsChanged={false} onStatusChange={setStatus} />
            </section>
        </div>
    );
};

export default KitchenPage;
