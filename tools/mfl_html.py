"""
tools/mfl_html.py

Minimal DOM builder over html.parser, used to read MFL's commissioner
report tables.

Why not regex: MFL nests tables inside the transaction cells, so
non-greedy `<table>.*?</table>` / `<tr>.*?</tr>` patterns split rows in
the wrong places and silently drop most of the report -- a first pass at
this returned 68 of 511 rows for 2026 and 0 for 2025 while still looking
plausible. Depth-aware parsing is the fix; bs4/lxml aren't installed.
"""

from html.parser import HTMLParser

VOID = {"br", "img", "input", "meta", "link", "hr", "area", "base",
        "col", "embed", "param", "source", "track", "wbr"}

# Tags MFL leaves unclosed in this report; closing one implicitly when the
# next starts keeps the tree from nesting rows inside their predecessors.
# Note "tr" closes only on another "tr": a <td> nests INSIDE the open row,
# it does not end it. Listing td/th here instead makes every cell a sibling
# of its row, which yields rows with zero cells.
IMPLICIT = {"tr": {"tr"}, "td": {"td", "th"}, "th": {"td", "th"},
            "option": {"option"}, "p": {"p"}, "li": {"li"}}


class Node:
    __slots__ = ("tag", "attrs", "children", "parent")

    def __init__(self, tag, attrs=None, parent=None):
        self.tag = tag
        self.attrs = attrs or {}
        self.children = []
        self.parent = parent

    def find_all(self, tag):
        out = []
        for c in self.children:
            if isinstance(c, Node):
                if c.tag == tag:
                    out.append(c)
                out.extend(c.find_all(tag))
        return out

    def kids(self, *tags):
        """Direct element children, transparently descending through
        thead/tbody/tfoot which browsers insert but MFL's markup omits."""
        out = []
        for c in self.children:
            if not isinstance(c, Node):
                continue
            if c.tag in ("thead", "tbody", "tfoot"):
                out.extend(c.kids(*tags))
            elif c.tag in tags:
                out.append(c)
        return out

    @property
    def text(self):
        parts = []
        for c in self.children:
            parts.append(c if isinstance(c, str) else c.text)
        return " ".join(" ".join(parts).split())

    def attr_values(self, *names):
        """Every value of the named attributes on this node and all
        descendants, in document order."""
        out = []
        for n in names:
            if n in self.attrs:
                out.append(self.attrs[n])
        for c in self.children:
            if isinstance(c, Node):
                out.extend(c.attr_values(*names))
        return out


class _Builder(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = Node("#root")
        self.cur = self.root

    def handle_starttag(self, tag, attrs):
        if tag in VOID:
            self.cur.children.append(Node(tag, dict(attrs), self.cur))
            return
        # close an unclosed sibling (<tr> without </tr>, etc.)
        openers = IMPLICIT.get(self.cur.tag)
        if openers and tag in openers:
            self.cur = self.cur.parent or self.root
        node = Node(tag, dict(attrs), self.cur)
        self.cur.children.append(node)
        self.cur = node

    def handle_endtag(self, tag):
        if tag in VOID:
            return
        n = self.cur
        while n is not self.root and n.tag != tag:
            n = n.parent or self.root
        if n is not self.root:
            self.cur = n.parent or self.root

    def handle_data(self, data):
        if data.strip():
            self.cur.children.append(data)


def parse_html(text: str) -> Node:
    b = _Builder()
    b.feed(text)
    return b.root
