// Syntax highlighting for the Code panel (SourcePanel.vue): a fixture is
// Twig mixed with HTML. Prism tokenises it; the panel renders the tokens as
// text spans itself, so the source is still never rendered as markup.
import './prismManual.js';
import Prism from 'prismjs/components/prism-core';
import 'prismjs/components/prism-markup';
import 'prismjs/components/prism-markup-templating';
import 'prismjs/components/prism-twig';
import 'prismjs/components/prism-clike';
import 'prismjs/components/prism-javascript';
import 'prismjs/components/prism-css';

// Token type -> Tailwind classes, tuned for the panel's zinc-900 background.
// The first type of a token (or of its aliases) found here wins; a nested
// token overrides its parent, so a string inside a Twig tag stays a string.
export const TOKEN_CLASSES = {
    comment: 'text-zinc-500 italic',
    delimiter: 'text-rose-400',
    'tag-name': 'text-violet-300',
    keyword: 'text-violet-300',
    operator: 'text-violet-300',
    string: 'text-emerald-300',
    'attr-value': 'text-emerald-300',
    'attr-name': 'text-amber-200',
    tag: 'text-sky-300',
    number: 'text-orange-300',
    boolean: 'text-orange-300',
    function: 'text-sky-300',
    selector: 'text-sky-300',
    property: 'text-amber-200',
    'class-name': 'text-amber-200',
    atrule: 'text-violet-300',
    regex: 'text-orange-300',
    'template-string': 'text-emerald-300',
    punctuation: 'text-zinc-400',
};

function classFor(token, inherited) {
    const types = [token.type, ...[].concat(token.alias ?? [])];
    // Twig's delimiters carry the alias "punctuation"; `delimiter` must win.
    const hit = types.find((t) => t === 'delimiter') ?? types.find((t) => TOKEN_CLASSES[t]);
    return hit ? TOKEN_CLASSES[hit] : inherited;
}

function flatten(stream, inherited, out) {
    for (const part of [].concat(stream)) {
        if (typeof part === 'string') {
            if (part === '') continue;
            const last = out[out.length - 1];
            if (last && last.class === inherited) last.text += part;
            else out.push({ text: part, class: inherited });
        } else {
            flatten(part.content, classFor(part, inherited), out);
        }
    }
    return out;
}

function highlight(source, language) {
    if (typeof source !== 'string' || source === '') return [];
    // What Prism.highlight() does, minus the HTML string at the end. The
    // hooks are where markup-templating lifts the Twig out, tokenises the
    // HTML around it and puts the Twig tokens back; a bare tokenize() would
    // see Twig only.
    const env = { code: source, grammar: Prism.languages[language], language };
    Prism.hooks.run('before-tokenize', env);
    env.tokens = Prism.tokenize(env.code, env.grammar);
    Prism.hooks.run('after-tokenize', env);
    return flatten(env.tokens, '', []);
}

// The source as a list of `{ text, class }` segments; joined, the texts are
// exactly the source. `class` is '' for plain text.
export function highlightTwig(source) {
    return highlight(source, 'twig');
}

// The same for the HTML a fixture renders (the panel's HTML tab).
export function highlightMarkup(source) {
    return highlight(source, 'markup');
}

// Any of the panel's languages: 'twig', 'markup', 'css', 'javascript'. An
// unknown one comes back as plain text.
export function highlightCode(source, language) {
    if (!Prism.languages[language]) return typeof source === 'string' && source !== '' ? [{ text: source, class: '' }] : [];
    return highlight(source, language);
}
