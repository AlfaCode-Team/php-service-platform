#!/usr/bin/env python3
"""Generate hkm-cli-usage.tex from hkm-cli-usage.md (the single source of truth).

A focused Markdown→LaTeX converter for the controlled subset this doc uses:
ATX headings (#, ##, ###), fenced code blocks, blockquotes, ordered/unordered
lists, **bold**, `inline code`, horizontal rules, and the leading title block.
Run by `zig build docs`, which then compiles the .tex with pdflatex.
"""

from __future__ import annotations
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
MD = HERE / "hkm-cli-usage.md"
TEX = HERE / "hkm-cli-usage.tex"

PREAMBLE = r"""% AUTO-GENERATED from hkm-cli-usage.md by build-docs.py — DO NOT EDIT.
% Regenerate with:  zig build docs   (or: python3 docs/build-docs.py)
\documentclass[11pt,a4paper]{article}

\usepackage[margin=2.2cm]{geometry}
\usepackage{xcolor}
\usepackage{listings}
\usepackage{titlesec}
\usepackage{enumitem}
\usepackage{fancyhdr}
\usepackage{parskip}
\usepackage{tcolorbox}
\usepackage[colorlinks=true,linkcolor=accent,urlcolor=accent]{hyperref}

\definecolor{accent}{HTML}{2D7D46}
\definecolor{ink}{HTML}{1A1A1A}
\definecolor{muted}{HTML}{6B6B6B}
\definecolor{codebg}{HTML}{F4F6F4}
\definecolor{rule}{HTML}{D8DED8}

\lstdefinestyle{sh}{
  basicstyle=\ttfamily\small,
  backgroundcolor=\color{codebg},
  frame=single, framerule=0pt, rulecolor=\color{rule},
  xleftmargin=10pt, xrightmargin=10pt, framexleftmargin=8pt,
  aboveskip=10pt, belowskip=10pt,
  columns=fullflexible, keepspaces=true, showstringspaces=false,
  breaklines=true,
  postbreak=\mbox{\textcolor{muted}{$\hookrightarrow$}\space},
}
\lstset{style=sh}

\titleformat{\section}{\Large\bfseries\color{accent}}{\thesection}{0.6em}{}
\titleformat{\subsection}{\large\bfseries\color{ink}}{\thesubsection}{0.6em}{}
\titleformat{\subsubsection}{\normalsize\bfseries\color{ink}}{}{0em}{}
\titlespacing*{\section}{0pt}{16pt}{6pt}

\pagestyle{fancy}\fancyhf{}
\renewcommand{\headrulewidth}{0.3pt}
\renewcommand{\footrulewidth}{0.3pt}
\lhead{\small\textcolor{muted}{hkm --- CLI Usage}}
\rhead{\small\textcolor{muted}{PhpServicePlatform}}
\cfoot{\small\textcolor{muted}{\thepage}}

\newtcolorbox{note}{colback=codebg,colframe=rule,boxrule=0.4pt,arc=2pt,
  left=8pt,right=8pt,top=6pt,bottom=6pt}

\begin{document}
"""

TITLEPAGE = r"""
\begin{titlepage}
\centering
\vspace*{3cm}
{\Huge\bfseries\color{accent} hkm}\\[4pt]
{\Large\bfseries The PhpServicePlatform launcher}\\[6pt]
{\large\color{muted} Command-line usage reference}\\[2cm]
\begin{tcolorbox}[colback=codebg,colframe=rule,width=0.8\textwidth,arc=3pt]
\centering\small
Scaffold projects, run them locally, and manage plugins --- kernel and project
--- including asset publishing and database migrations.
\end{tcolorbox}
\vfill
{\small\color{muted} AlfacodeTeam PhpServicePlatform Framework}\\
{\small\color{muted} Generated \today}
\end{titlepage}

\tableofcontents
\newpage
"""

POSTAMBLE = "\n\\end{document}\n"


def esc(text: str) -> str:
    """Escape LaTeX specials in prose."""
    out = []
    for ch in text:
        if ch == "\\":
            out.append(r"\textbackslash{}")
        elif ch in "&%$#_{}":
            out.append("\\" + ch)
        elif ch == "~":
            out.append(r"\textasciitilde{}")
        elif ch == "^":
            out.append(r"\textasciicircum{}")
        else:
            out.append(ch)
    return "".join(out)


def inline(text: str) -> str:
    """Convert `code`, **bold** and *emphasis*, escaping the rest. Code spans
    are escaped and wrapped in \\texttt so backslashes/underscores render
    literally. `**` is matched before `*` so bold wins over emphasis."""
    parts = re.split(r"(`[^`]*`|\*\*[^*]+\*\*|\*[^*]+\*)", text)
    out = []
    for p in parts:
        if len(p) >= 2 and p[0] == "`" and p[-1] == "`":
            out.append(r"\texttt{" + esc(p[1:-1]) + "}")
        elif p.startswith("**") and p.endswith("**"):
            out.append(r"\textbf{" + inline(p[2:-2]) + "}")
        elif p.startswith("*") and p.endswith("*") and len(p) > 2:
            out.append(r"\emph{" + inline(p[1:-1]) + "}")
        else:
            out.append(esc(p))
    return "".join(out)


def convert(md: str) -> str:
    lines = md.splitlines()
    body: list[str] = []
    i = 0
    n = len(lines)
    # Skip the leading H1 + its intro paragraph (rendered by the title page).
    # We start emitting once we hit the first '## ' / '# ' after the title.
    started = False

    list_stack: list[str] = []  # 'itemize' | 'enumerate'

    def close_lists():
        while list_stack:
            body.append("\\end{" + list_stack.pop() + "}")

    while i < n:
        line = lines[i]

        # fenced code block
        m = re.match(r"^```", line)
        if m:
            close_lists()
            i += 1
            code: list[str] = []
            while i < n and not lines[i].startswith("```"):
                code.append(lines[i])
                i += 1
            i += 1  # skip closing fence
            body.append("\\begin{lstlisting}")
            body.extend(code)
            body.append("\\end{lstlisting}")
            continue

        # headings
        h = re.match(r"^(#{1,3})\s+(.*)$", line)
        if h:
            close_lists()
            level, txt = len(h.group(1)), h.group(2).strip()
            if level == 1:
                # first H1 is the doc title -> skip (title page handles it)
                i += 1
                continue
            started = True
            cmd = {2: "section", 3: "subsection"}.get(level, "subsubsection")
            # demote: md ## -> section, ### -> subsection
            cmd = "section" if level == 2 else "subsection"
            body.append(f"\\{cmd}{{{inline(txt)}}}")
            i += 1
            continue

        if not started:
            i += 1
            continue

        # horizontal rule -> ignore (sections separate themselves)
        if re.match(r"^---+\s*$", line):
            close_lists()
            i += 1
            continue

        # blockquote (possibly multi-line) -> note box
        if line.startswith(">"):
            close_lists()
            quote: list[str] = []
            while i < n and lines[i].startswith(">"):
                quote.append(lines[i].lstrip(">").strip())
                i += 1
            text = " ".join(q for q in quote if q)
            body.append("\\begin{note}")
            body.append(inline(text))
            body.append("\\end{note}")
            continue

        # unordered list
        ul = re.match(r"^[-*]\s+(.*)$", line)
        if ul:
            if not list_stack or list_stack[-1] != "itemize":
                close_lists()
                body.append("\\begin{itemize}[leftmargin=1.4em,itemsep=2pt]")
                list_stack.append("itemize")
            body.append("\\item " + inline(ul.group(1)))
            i += 1
            continue

        # ordered list
        ol = re.match(r"^\d+\.\s+(.*)$", line)
        if ol:
            if not list_stack or list_stack[-1] != "enumerate":
                close_lists()
                body.append("\\begin{enumerate}[leftmargin=1.6em,itemsep=2pt]")
                list_stack.append("enumerate")
            body.append("\\item " + inline(ol.group(1)))
            i += 1
            continue

        # blank line
        if line.strip() == "":
            close_lists()
            body.append("")
            i += 1
            continue

        # paragraph (gather until blank / structural line)
        para: list[str] = [line]
        i += 1
        while i < n and lines[i].strip() != "" and not re.match(
            r"^(#{1,3}\s|```|>|[-*]\s|\d+\.\s|---+\s*$)", lines[i]
        ):
            para.append(lines[i])
            i += 1
        close_lists()
        body.append(inline(" ".join(para)))
        body.append("")

    close_lists()
    return PREAMBLE + TITLEPAGE + "\n".join(body) + POSTAMBLE


def main() -> int:
    if not MD.exists():
        print(f"error: {MD} not found", file=sys.stderr)
        return 1
    TEX.write_text(convert(MD.read_text()), encoding="utf-8")
    print(f"generated {TEX.name} from {MD.name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
