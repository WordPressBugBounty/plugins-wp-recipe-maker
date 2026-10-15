import ApiWrapper from '../ApiWrapper';
import AjaxWrapper from '../AjaxWrapper';

const templateEndpoint = wprm_admin.endpoints.template;
const debounceTime = 500;

let previewPromisesByContext = {};
let previewRequestsByContext = {};
let previewRequestsTimerByContext = {};
let previewRecipeByContext = {};

export default {
    previewShortcode(uid, shortcode, recipeId, previewContext = 'default') {
        if ( ! previewPromisesByContext[previewContext] ) {
            previewPromisesByContext[previewContext] = [];
        }
        if ( ! previewRequestsByContext[previewContext] ) {
            previewRequestsByContext[previewContext] = {};
        }

        previewRequestsByContext[previewContext][uid] = shortcode;
        previewRecipeByContext[previewContext] = recipeId;

        clearTimeout(previewRequestsTimerByContext[previewContext]);
        previewRequestsTimerByContext[previewContext] = setTimeout(() => {
            this.previewShortcodes( previewContext );
        }, debounceTime);

        return new Promise( r => previewPromisesByContext[previewContext].push( r ) );
    },
    previewShortcodes( previewContext = 'default' ) {
        const thesePromises = previewPromisesByContext[previewContext] || [];
        const theseRequests = previewRequestsByContext[previewContext] || {};
        const previewRecipe = previewRecipeByContext[previewContext] || false;
        previewPromisesByContext[previewContext] = [];
        previewRequestsByContext[previewContext] = {};
        previewRecipeByContext[previewContext] = false;

        const data = {
            recipeId: previewRecipe,
            shortcodes: theseRequests,
        };

        fetch(`${templateEndpoint}/preview`, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wprm_admin.api_nonce,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        }).then(response => {
            return response.json().then(json => {
                let result = response.ok ? json.preview : {};

                thesePromises.forEach( r => r( result ) );
            });
        });
    },
    searchRecipes(input) {
        return AjaxWrapper.call('wprm_search_recipes', {
            search: input,
        }).then((data) => {
            // Return recipes_with_id if available, otherwise empty array.
            return data && data.recipes_with_id ? data.recipes_with_id : [];
        });
    },
    save(template) {
        const data = {
            template,
        };

        return fetch(templateEndpoint, { method: 'POST', headers: { 'X-WP-Nonce': wprm_admin.api_nonce, 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(async response => {
            let result;
            try { result = await response.json(); }
            catch (e) { throw new Error('The server did not confirm the save. Your changes are still available in the editor.'); }
            if (!response.ok) {
                const error = new Error(result?.message || 'Template save failed.');
                error.code = result?.code;
                error.data = result?.data;
                // WordPress rejects these authentication/permission errors before the save callback.
                error.saveNotStarted = [401, 403].includes(response.status)
                    && ['rest_cookie_invalid_nonce', 'rest_forbidden'].includes(error.code);
                throw error;
            }
            if (!result || typeof result.slug !== 'string' || !result.slug || typeof result.html !== 'string' || typeof result.css !== 'string' || !Number.isInteger(result.revision) || result.revision < 1) {
                throw new Error('The server did not return a saved template. Your changes are still available in the editor.');
            }
            return result;
        });
    },
    delete(slug) {
        const data = {
            slug,
        };

        return ApiWrapper.call( templateEndpoint, 'DELETE', data );
    },
    suggestTemplateChanges(options) {
        const aiTemplateEditor = 'undefined' !== typeof wprm_admin_template && wprm_admin_template.ai_template_editor ? wprm_admin_template.ai_template_editor : {};

        if ( ! aiTemplateEditor.enabled || ! aiTemplateEditor.endpoint ) {
            return Promise.reject( {
                message: 'AI Assistant is not available.',
            } );
        }

        return ApiWrapper.call( aiTemplateEditor.endpoint, 'POST', options );
    },
};
