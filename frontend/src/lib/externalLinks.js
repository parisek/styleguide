// Resolves an item's external-link metadata (`asana`, `figma`, `drupal`,
// `web` YAML keys) into a renderable list. Order matches the legacy badge
// row: Asana -> Figma -> Drupal -> Web. Consolidates three near-identical
// copies from frontend/components/linkBar.js and frontend/components/overview.js
// (`linksFor`, and the inline decorate() shape in _buildForwardMap/_buildReverseMap).

export function externalLinksFor(item) {
    if (!item) return [];
    return [
        { key: 'asana', url: item.asana, label: 'Asana' },
        { key: 'figma', url: item.figma, label: 'Figma' },
        { key: 'drupal', url: item.drupal, label: 'Drupal' },
        { key: 'web', url: item.web, label: 'Web' },
    ].filter((l) => l.url);
}
