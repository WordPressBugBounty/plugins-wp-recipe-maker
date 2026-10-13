// Debounce typing and keep slow searches from overlapping on the server.
export default function createDebouncedSearch(search, delay = 350) {
    let timer = null;
    let pending = null;
    let inFlight = false;

    const clearPending = () => {
        clearTimeout(timer);
        timer = null;

        if (pending) {
            pending.resolve([]);
            pending = null;
        }
    };

    const run = () => {
        if (timer || inFlight || !pending) {
            return;
        }

        const request = pending;
        pending = null;
        inFlight = true;

        Promise.resolve()
            .then(() => search(request.input))
            .then((options) => request.resolve(options))
            .catch(() => request.resolve([]))
            .finally(() => {
                inFlight = false;
                run();
            });
    };

    return {
        loadOptions(input) {
            clearPending();

            if (!input) {
                return Promise.resolve([]);
            }

            return new Promise((resolve) => {
                pending = { input, resolve };
                timer = setTimeout(() => {
                    timer = null;
                    run();
                }, delay);
            });
        },
        cancel: clearPending,
    };
}
