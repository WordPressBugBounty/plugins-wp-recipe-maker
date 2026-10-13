import React, { useState } from 'react';

import { __wprm } from 'Shared/Translations';

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
                    <h1>WPRM Kitchen <span className="wprm-kitchen-beta">{ __wprm( 'Beta' ) }</span></h1>
                    <p>{ __wprm( 'WPRM Kitchen is currently in beta. Features may change as we continue improving it.' ) }</p>
                    <p>
                        {
                            connected
                            ? __wprm( 'This site is securely connected. Open Kitchen to work with your recipes and connect your other WordPress sites.' )
                            : __wprm( 'Bring your WP Recipe Maker sites together in one secure workspace and manage your recipes beyond WordPress.' )
                        }
                    </p>
                    {
                        connected && status.kitchen_origin
                        ?
                        <a className="button button-primary" href={status.kitchen_origin} target="_blank" rel="noopener noreferrer">{ __wprm( 'Open WPRM Kitchen' ) }</a>
                        : null
                    }
                </div>
                <div className="wprm-kitchen-hero-mark" aria-hidden="true">
                    {/* Keep the mark in sync with wprm-kitchen/src/components/brand-mark.tsx. */}
                    <svg viewBox="0 0 57 56" focusable="false">
                        <path d="M54.645 38.965c1.002.321 1.476 1.458.955 2.351-6.819 11.742-21.38 17.587-35.05 13.258C6.872 50.238-1.377 37.16.191 23.754c.119-1.021 1.186-1.677 2.187-1.355l52.267 16.566Z" fill="currentColor" />
                        <path d="M47.82 29.773c4.559 0 8.255-3.604 8.255-8.049s-3.696-8.048-8.256-8.048c-4.559 0-8.255 3.603-8.255 8.048s3.696 8.049 8.255 8.049Z" fill="currentColor" />
                        <path d="m30.478 2.03 9.89 1.721c1.383.238 2.016 1.818 1.166 2.91l-5.877 7.548c-.876 1.124-2.662.9-3.228-.392l-4.013-9.269c-.58-1.336.593-2.768 2.062-2.511V2.03Z" fill="currentColor" />
                        <path d="M26.532 24.493a3.92 3.92 0 0 1-.738-.276l-1.265-.72c-3.413-1.946-5.58-5.543-5.66-9.397v-.148c-.065-3.276-1.627-6.308-4.282-8.312l-1.825-1.375c-1.067-.803-1.259-2.3-.435-3.334.824-1.04 2.359-1.226 3.42-.424l1.825 1.375c3.827 2.891 6.074 7.259 6.166 11.98v.148c.047 2.21 1.285 4.271 3.242 5.383l1.265.719c1.16.662 1.555 2.12.876 3.25-.54.906-1.6 1.33-2.602 1.13h.013Z" fill="currentColor" />
                    </svg>
                </div>
            </section>

            {
                connected
                ?
                <section className="wprm-kitchen-overview">
                    <h2>{ __wprm( 'Your Kitchen connection' ) }</h2>
                    <div className="wprm-kitchen-cards">
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'Connection' ) }</strong>
                            <span>{ __wprm( 'Securely connected' ) }</span>
                            {status.connection && status.connection.connected_at ? <small>{ __wprm( 'Since %s' ).replace( /%(?:1\$)?s/g, () => status.connection.connected_at ) }</small> : null}
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'Site access' ) }</strong>
                            <span>{premiumActive ? __wprm( 'Recipes and analytics available' ) : __wprm( 'Connection only' )}</span>
                            {! premiumActive ? <small>{ __wprm( 'WP Recipe Maker Premium unlocks recipe and analytics access.' ) }</small> : null}
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'More sites' ) }</strong>
                            <span>{ __wprm( 'Connect every site to the same Kitchen workspace.' ) }</span>
                        </div>
                    </div>
                </section>
                :
                <section className="wprm-kitchen-overview">
                    <h2>{ __wprm( 'Why connect to WPRM Kitchen?' ) }</h2>
                    <div className="wprm-kitchen-cards">
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'One workspace' ) }</strong>
                            <span>{ __wprm( 'Bring multiple WP Recipe Maker sites together under one Kitchen account.' ) }</span>
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'More templates' ) }</strong>
                            <span>{ __wprm( 'Browse new recipe card designs and keep backups of your own templates.' ) }</span>
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'Simple access' ) }</strong>
                            <span>{ __wprm( 'Connect once here, then manage access centrally from WPRM Kitchen.' ) }</span>
                        </div>
                        <div className="wprm-kitchen-card">
                            <strong>{ __wprm( 'Secure by design' ) }</strong>
                            <span>{ __wprm( 'No WordPress password is shared, and access can be revoked at any time.' ) }</span>
                        </div>
                    </div>
                </section>
            }

            <section className="wprm-kitchen-connection-panel">
                <div className="wprm-kitchen-connection-heading">
                    <h2>{connected ? __wprm( 'Manage connection' ) : __wprm( 'Connect this site' )}</h2>
                    <p>{connected ? __wprm( 'Test the connection or disconnect this site.' ) : __wprm( 'You will be sent to WPRM Kitchen to log in and choose a workspace.' )}</p>
                </div>
                <KitchenConnection settingsChanged={false} onStatusChange={setStatus} />
            </section>
        </div>
    );
};

export default KitchenPage;
