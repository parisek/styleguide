# Tools UI contract 0.1.0

The contract names existing styleguide chrome values. It does not restyle project
components inside preview iframes. The receiving tool uses Tailwind 4 and imports
`tokens.css` after Tailwind. Its source scan includes its own Vue and JS files.

| Role | Utilities | Meaning |
| --- | --- | --- |
| Surface | `bg-ui-surface`, `text-ui-text` | Control background and primary text |
| Toolbar | `bg-ui-toolbar`, `min-h-ui-toolbar` | Stable shell toolbar |
| Border | `border-ui-border`, `ring-ui-border` | Panel and frame boundaries |
| Idle control | `text-ui-muted`, `border-ui-control-border` | Available, unselected control |
| Hover control | `hover:text-ui-text`, `hover:border-ui-control-hover-border` | Pointer feedback |
| Active control | `bg-ui-active-surface`, `text-ui-active-text` | Selected control |
| Focus | `focus-visible:outline-ui-focus` | Keyboard focus, with visible outline |
| Preview | `bg-ui-preview` | White document canvas in both shell themes |
| Geometry | `h-ui-control`, `rounded-ui-pill`, `rounded-ui-panel` | Existing control and frame dimensions |

The shell adds `.dark` to resolve dark values. Application code owns the light,
dark, and system preference, storage fallback, focus behavior, and semantics.
Text labels remain visible. A receiving tool defines its own diagnostic meanings.
A report class must not depend on a color token.

## Synchronization

Copy only `tokens.css` and `contract.json` from one reviewed source commit.
Record the source repository, full commit, contract version, file hashes, and MIT
licence in receiver metadata. Include the licence in the receiving repository.
Do not copy catalog stores, routing, preview relays, or application state.

An update is an explicit PR in each receiver. Review local deviations before
copying. The receiver may keep an older contract. Neither installation nor the
running application fetches files from the other package. A future sync tool
must use a file whitelist and stop when a locally edited file would be replaced.

The source commit belongs in receiver metadata, not in this source manifest.
This avoids a self-referencing commit hash. Build distributions independently.

## Verification

Pills, the viewport toolbar, and comparison frames exercise this first contract.
Browser checks verify resolved light/dark and hover colors, focus, geometry,
preview isolation, and responsive overflow. Existing component and browser
suites continue to verify behavior. Expand the contract only with an adopted use.
