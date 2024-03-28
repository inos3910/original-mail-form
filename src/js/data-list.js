(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const tables = document.querySelectorAll(
      'body[class*="post-type-omf_db_"] table.wp-list-table'
    );
    for (const table of tables) {
      table.outerHTML =
        '<div class="omf-table-wrapper">' + table.outerHTML + '</div>';
    }
  });
})();
