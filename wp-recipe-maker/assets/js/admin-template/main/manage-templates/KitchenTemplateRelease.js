import React, { useEffect, useState } from 'react';

// Public catalog metadata only. The request sends no WordPress or Kitchen cookies.
const KitchenTemplateRelease = ({ template, kitchen, hasUnsavedChanges = false }) => {
    const [state, setState] = useState({ status: 'loading', releases: null });
    const templateId = template.kitchen.template_id;
    const versionId = template.kitchen.version_id;
    const origin = kitchen.url;
    useEffect(() => {
        const controller = new AbortController();
        let active = true;
        const timer = setTimeout(() => controller.abort(), 8000);
        fetch(`${ origin }/api/catalog/templates/${ encodeURIComponent(templateId) }/releases?installedVersionId=${ encodeURIComponent(versionId) }`, {
            credentials: 'omit', signal: controller.signal,
        }).then(async response => {
            if (response.status === 404) return { status: 'private', releases: null };
            if (!response.ok) throw new Error('Release check failed.');
            const releases = await response.json();
            const valid = release => release && typeof release.id === 'string'
                && (release.releaseVersion === null || typeof release.releaseVersion === 'string')
                && typeof release.releaseNotes === 'string' && typeof release.releasedAt === 'string'
                && /^[a-f0-9]{64}$/.test(release.checksum) && typeof release.algorithm === 'string';
            if (!valid(releases.latest) || (releases.installed && !valid(releases.installed))) throw new Error('Invalid releases.');
            return { status: 'ready', releases };
        }).then(result => {
            if (active) setState(result);
        }).catch(() => {
            if (active) setState({ status: 'error', releases: null });
        }).finally(() => clearTimeout(timer));
        return () => { active = false; clearTimeout(timer); controller.abort(); };
    }, [origin, templateId, versionId]);

    const { latest, installed } = state.releases || {};
    const label = release => release.releaseVersion || `Saved ${ release.releasedAt.slice(0, 10) }`;
    const changed = installed && (template.kitchenChecksum !== installed.checksum || template.kitchenAlgorithm !== installed.algorithm);
    const update = latest && installed && latest.id !== installed.id;
    const href = `${ origin }/app/templates?view=sites&site=${ encodeURIComponent(kitchen.connection_id) }&slug=${ encodeURIComponent(template.slug) }`;
    return <div className="wprm-main-container notice notice-info inline" style={{ margin: '12px 0' }}>
        <p>{installed
            ? `${ changed ? 'Based on' : 'Installed:' } ${ label(installed) }${ changed ? ', customized locally.' : '' }`
            : state.status === 'loading' ? 'Checking Kitchen template releases…'
                : state.status === 'error' ? 'Could not check for template updates. Your template still works.'
                    : 'Template saved in Kitchen.'}</p>
        {update ? <>
            <p><strong>Update available: { label(latest) }</strong></p>
            {latest.releaseNotes ? <p style={{ whiteSpace: 'pre-line' }}>{latest.releaseNotes}</p> : null}
            <p>{changed
                ? 'Save your local version or install the new release separately, then compare before changing your active template.'
                : 'Updating in Kitchen keeps this template’s activation and saves an undo version first.'}</p>
        </> : null}
        {hasUnsavedChanges ? <p>Save or discard your unsaved edits before reviewing a template update.</p> : null}
        <p><a href={href} target="_blank" rel="noopener noreferrer">{update ? 'Review update in Kitchen ↗' : 'View template in Kitchen ↗'}</a></p>
    </div>;
};
export default KitchenTemplateRelease;
