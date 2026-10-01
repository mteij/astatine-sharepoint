// Filter box, sortable columns, row selection and keyboard navigation for the committee list and the file explorer.
(function () {
    'use strict';

    function items(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('[data-filter-item]'));
    }

    // Filter: hides items whose data-name does not contain the typed text.
    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input.matches || !input.matches('input[data-filter-scope]')) {
            return;
        }
        var scope = document.querySelector(input.getAttribute('data-filter-scope'));
        if (!scope) {
            return;
        }
        var query = input.value.trim().toLowerCase();
        var rows = items(scope);
        var shown = 0;
        rows.forEach(function (row) {
            var match = query === '' || (row.getAttribute('data-name') || '').indexOf(query) !== -1;
            row.hidden = !match;
            shown += match ? 1 : 0;
        });

        var empty = document.querySelector(input.getAttribute('data-filter-empty'));
        if (empty) {
            empty.hidden = shown !== 0 || rows.length === 0;
        }
        var countSelector = input.getAttribute('data-filter-count');
        var count = countSelector ? document.querySelector(countSelector) : null;
        if (count) {
            if (!count.hasAttribute('data-original')) {
                count.setAttribute('data-original', count.textContent);
            }
            count.textContent = query === '' ? count.getAttribute('data-original') : shown + ' of ' + rows.length + ' items';
        }
    });

    // Sort: folders always stay above files, the clicked column orders each group.
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('button[data-sort]') : null;
        if (!button) {
            return;
        }
        var table = button.closest('table');
        var header = button.parentNode;
        var key = button.getAttribute('data-sort');
        var direction = header.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
        var numeric = key === 'modified' || key === 'size';

        Array.prototype.forEach.call(table.querySelectorAll('th[aria-sort]'), function (th) {
            th.removeAttribute('aria-sort');
        });
        header.setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending');

        var body = table.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        rows.sort(function (a, b) {
            var folders = Number(b.getAttribute('data-folder')) - Number(a.getAttribute('data-folder'));
            if (folders !== 0) {
                return folders;
            }
            var x = a.getAttribute('data-' + key) || '';
            var y = b.getAttribute('data-' + key) || '';
            var order = numeric ? Number(x) - Number(y) : x.localeCompare(y, undefined, { numeric: true });
            return (order || a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'))) * direction;
        });
        rows.forEach(function (row) {
            body.appendChild(row);
        });
    });

    // Selection: click selects a row, double-click or Enter opens it, arrows move, Backspace goes up.
    function visibleRows() {
        return items(document).filter(function (row) {
            return !row.hidden;
        });
    }

    function select(row) {
        items(document).forEach(function (other) {
            other.classList.remove('selected');
            other.removeAttribute('aria-selected');
        });
        if (row) {
            row.classList.add('selected');
            row.setAttribute('aria-selected', 'true');
            row.scrollIntoView({ block: 'nearest' });
        }
    }

    function open(row) {
        var link = row && row.querySelector('a.name');
        if (link) {
            link.click();
        }
    }

    document.addEventListener('click', function (event) {
        var row = event.target.closest ? event.target.closest('tr[data-filter-item]') : null;
        if (row) {
            select(row);
        }
    });

    document.addEventListener('dblclick', function (event) {
        var row = event.target.closest ? event.target.closest('tr[data-filter-item]') : null;
        if (row && !event.target.closest('a')) {
            open(row);
        }
    });

    document.addEventListener('keydown', function (event) {
        var tag = event.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        var rows = visibleRows();
        var current = document.querySelector('tr.selected');
        var index = rows.indexOf(current);

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
            if (rows.length === 0) {
                return;
            }
            event.preventDefault();
            if (event.key === 'Home') {
                index = 0;
            } else if (event.key === 'End') {
                index = rows.length - 1;
            } else if (event.key === 'ArrowDown') {
                index = Math.min(index + 1, rows.length - 1);
            } else {
                index = index < 0 ? 0 : Math.max(index - 1, 0);
            }
            select(rows[index]);
        } else if (event.key === 'Enter' && current && !current.hidden) {
            open(current);
        } else if (event.key === 'Backspace') {
            var up = document.querySelector('a.btn.up');
            if (up) {
                event.preventDefault();
                up.click();
            }
        }
    });

    // A long path is wider than the address bar on a phone: start at its end, where the current folder is.
    var address = document.querySelector('.address');
    function scrollPathToEnd() {
        address.scrollLeft = address.scrollWidth;
    }
    if (address) {
        scrollPathToEnd();
        // Text gets wider once the web font has loaded, so measure again then.
        window.addEventListener('load', scrollPathToEnd);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(scrollPathToEnd);
        }
    }
}());
