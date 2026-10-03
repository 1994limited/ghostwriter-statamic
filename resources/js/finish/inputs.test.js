import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rowAnchor } from './inputs.js';

// Just enough of an element tree: parentElement and children.
const node = (name, children = [], attrs = {}) => {
    const element = { name, children, parentElement: null, hasAttribute: (key) => key in attrs, nodeType: 1, matches: (selector) => selector.split(',').map((s) => s.trim()).includes(name) };
    children.forEach((child) => (child.parentElement = element));

    return element;
};

test('a row goes after the wrappers that hold only the input, inside its field or cell', () => {
    const input = node('input');
    const box = node('div.box', [input]);
    const outer = node('div.outer', [box]);
    const field = node('.form-group', [node('label'), outer, node('div.help')]);
    const stop = (element) => element.matches('.form-group, td');

    assert.equal(rowAnchor(input, stop), outer);
    assert.equal(field.children[1], outer);

    const cellInput = node('input');
    const cell = node('td', [node('div', [cellInput])]);
    node('tr', [cell]);
    assert.equal(rowAnchor(cellInput, stop), cell.children[0], 'never the cell itself');

    const withRow = node('input');
    const wrap = node('div', [withRow, node('div', [], { 'data-gw-gap-row': '' })]);
    node('.form-group', [node('label'), wrap]);
    assert.equal(rowAnchor(withRow, stop), wrap, 'its own row doesn’t count as a sibling');
});
