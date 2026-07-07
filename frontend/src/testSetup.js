// jsdom does not implement ResizeObserver. Every component that measures its
// own DOM box (the viewport composable's observeContainer/observeWrapper,
// PreviewPane's iframe-content auto-fit) needs a no-op stand-in so mounting
// them in a test doesn't throw `ResizeObserver is not defined`. Tests that
// need actual resize callbacks invoke the stored callback manually — see
// PreviewPane.spec.js (Task 8) for that pattern.
class ResizeObserverStub {
    constructor(callback) {
        this.callback = callback;
    }
    observe() {}
    unobserve() {}
    disconnect() {}
}

global.ResizeObserver = ResizeObserverStub;
