import React, { Component, Fragment } from 'react';

import '../../../../css/admin/dashboard/analytics.scss';

import { __wprm } from 'Shared/Translations';
import Api from 'Shared/Api';

import Block from '../../layout/Block';
import EnableAnalytics from './EnableAnalytics';
import Charts from './Charts';

export default class Analytics extends Component {
    constructor(props) {
        super(props);

        this.state = {
            analyticsEnabled: wprm_admin_dashboard.settings.analytics_enabled,
        }
    }

    render() {
        return (
            <Block
                title={ __wprm( 'Analytics' ) }
            >
                {                
                    this.state.analyticsEnabled
                    ?
                    <Fragment>
                        <Charts />
                    </Fragment>
                    :
                    <EnableAnalytics
                        onEnable={() => {
                            // Save setting.
                            Api.settings.save({
                                analytics_enabled: true,
                            });

                            // Update component.
                            this.setState({
                                analyticsEnabled: true,
                            });
                        }}
                    />
                }
            </Block>
        );
    }
}