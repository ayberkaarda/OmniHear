"""Shared text normalisation and tokenisation for the analysis pipeline.

Every stage (language detection, sentiment, category, keywords) consumes
text through this module so that a single definition of "word" and a
single case-folding rule apply throughout. Two properties matter here:

1. **Turkish-aware case folding.** Python's ``str.lower()`` implements the
   invariant Unicode mapping: ``"I"`` becomes ``"i"`` and ``"İ"`` becomes
   ``"i"`` plus a combining dot (U+0307). Both are wrong for Turkish,
   where dotted and dotless ``i`` are distinct letters. :func:`fold_case`
   applies the Turkish mapping before delegating to ``str.lower()``.

2. **Diacritic folding as a *second* lookup key, never a replacement.**
   Real App Store reviews are frequently written without Turkish
   diacritics ("cok guzel" for "çok güzel"). Lexicon lookups therefore try
   the folded form as well, but the original form is preserved so that
   diacritic-bearing text stays a language-detection signal.

No module-level mutable state: every function here is pure, which is what
lets the analyzers satisfy the stateless-service invariant.
"""

import re
import unicodedata

# Unicode letters only: excludes digits, underscore, punctuation and emoji.
_WORD_RE = re.compile(r"[^\W\d_]+", re.UNICODE)

# Characters that may sit between two words without ending a phrase.
# Anything else (punctuation, digits, emoji) is a phrase boundary.
_SOFT_GAP_CHARS = frozenset(" \t\n\r'’-–")

# Turkish case folding: these two must be handled before str.lower().
_TR_LOWER_MAP = str.maketrans({"İ": "i", "I": "ı"})

# Diacritic folding for lexicon lookups (see module docstring, point 2).
_ASCII_FOLD_MAP = str.maketrans(
    {
        "ç": "c",
        "ğ": "g",
        "ı": "i",
        "ö": "o",
        "ş": "s",
        "ü": "u",
        "â": "a",
        "î": "i",
        "û": "u",
    }
)


def fold_case(text: str) -> str:
    """Lower-case `text` using the Turkish mapping for dotted/dotless I."""
    return text.translate(_TR_LOWER_MAP).lower()


def fold_diacritics(text: str) -> str:
    """Return the ASCII-folded form of an already case-folded string.

    Only the Turkish letters are mapped explicitly; anything else is left
    to NFKD decomposition with combining marks stripped, so that e.g.
    "café" folds to "cafe" as well.
    """
    mapped = text.translate(_ASCII_FOLD_MAP)
    decomposed = unicodedata.normalize("NFKD", mapped)
    return "".join(ch for ch in decomposed if not unicodedata.combining(ch))


def tokenize(text: str) -> list[str]:
    """Case-folded word tokens, in order. Digits and emoji are dropped."""
    return _WORD_RE.findall(fold_case(text))


def lookup_keys(token: str) -> tuple[str, str]:
    """Return (token, diacritic-folded token) for two-pass lexicon lookup.

    The two values are equal for pure-ASCII tokens; callers must therefore
    not count a hit twice.
    """
    return token, fold_diacritics(token)


def split_phrases(text: str) -> list[list[str]]:
    """Split `text` into runs of adjacent word tokens.

    Punctuation, digits and emoji act as separators, so "great app, but
    slow" yields ``[["great", "app"], ["but", "slow"]]``. The keyword
    extractor uses these runs as candidate phrase boundaries.
    """
    folded = fold_case(text)
    phrases: list[list[str]] = []
    current: list[str] = []
    cursor = 0

    for match in _WORD_RE.finditer(folded):
        gap = folded[cursor : match.start()]
        if current and any(ch not in _SOFT_GAP_CHARS for ch in gap):
            phrases.append(current)
            current = []
        current.append(match.group())
        cursor = match.end()

    if current:
        phrases.append(current)
    return phrases


def has_letters(text: str) -> bool:
    """True when `text` contains at least one Unicode letter."""
    return _WORD_RE.search(text) is not None
