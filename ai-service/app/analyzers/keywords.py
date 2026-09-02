"""Statistical keyword extraction (RAKE) for Turkish and English.

RAKE — Rapid Automatic Keyword Extraction — is used here rather than
TF-IDF because a single feedback item is analysed in isolation: there is
no document collection at request time from which to compute an inverse
document frequency, and the service is stateless, so it cannot accumulate
one. RAKE needs only the document itself.

The algorithm:

1. Split the text into *candidate phrases* at stopwords and at any
   punctuation, digit or emoji boundary (:func:`app.analyzers.text.split_phrases`
   handles the latter). What survives between two stopwords is a content
   phrase such as "sürekli çöküyor" or "dark mode".
2. Build a co-occurrence graph over the words inside those phrases. Each
   word gets a ``degree`` (how many word slots it co-occurs with,
   including itself) and a ``frequency``.
3. Score each word ``degree / frequency`` — this rewards words that
   habitually appear inside longer phrases over words that merely repeat.
   A phrase scores as the sum of its member words.

Ranking is fully deterministic: descending score, then ascending position
of first occurrence, then alphabetically. Two identical inputs always
produce the same list in the same order, which the pipeline relies on for
a reproducible ``model_version`` story.
"""

from app.analyzers.text import fold_diacritics, split_phrases

MAX_KEYWORDS = 10

# Longest candidate phrase, in words. Longer runs are truncated from the
# left, since the head of a Turkish noun phrase carries the modifiers.
_MAX_PHRASE_WORDS = 3

# Single-character tokens are never informative as keywords.
_MIN_WORD_LENGTH = 2

# Stopwords are matched against both the raw and the diacritic-folded
# token, so "çok" and "cok" are both stopped.
_STOPWORDS = frozenset(
    """
    ve veya ama ancak fakat cunku ki de da ile icin gibi kadar sonra once
    bir bu su o bunu sunu onu bana sana ona bizim sizin onlarin onlar
    cok az daha en hic hep her bazi biraz yine hala zaten sadece ise iken
    var yok degil evet hayir tamam belki nasil neden nicin ne kim nerede
    benim senin onun bende sende onda bize size onlara diye ya
    olarak uzere ragmen dolayi sayede yerine bende falan filan
    ben sen biz siz
    the a an and or but because that this these those there here
    is are was were be been being am do does did doing done
    have has had having will would can could should shall may might must
    i you he she it we they me him her us them my your his its our their
    of to in on at for from with without about into over under after before
    not no yes very just also too only even still always never
    what when where which who whom why how
    s t d ll m re ve
    """.split()
)


def extract(text: str, limit: int = MAX_KEYWORDS) -> list[str]:
    """Return up to `limit` ranked keyword phrases from `text`."""
    candidates = _candidate_phrases(text)
    if not candidates:
        return []

    degree: dict[str, int] = {}
    frequency: dict[str, int] = {}
    for phrase in candidates:
        span = len(phrase)
        for word in phrase:
            degree[word] = degree.get(word, 0) + span
            frequency[word] = frequency.get(word, 0) + 1

    word_score = {word: degree[word] / frequency[word] for word in degree}

    ranked: dict[str, tuple[float, int]] = {}
    for position, phrase in enumerate(candidates):
        joined = " ".join(phrase)
        score = sum(word_score[word] for word in phrase)
        existing = ranked.get(joined)
        if existing is None or score > existing[0]:
            ranked[joined] = (score, position if existing is None else existing[1])

    ordered = sorted(ranked.items(), key=lambda item: (-item[1][0], item[1][1], item[0]))
    return [phrase for phrase, _ in ordered[:limit]]


def _candidate_phrases(text: str) -> list[list[str]]:
    """Split `text` into stopword-delimited runs of content words."""
    candidates: list[list[str]] = []

    for run in split_phrases(text):
        current: list[str] = []
        for token in run:
            if _is_stopword(token) or len(token) < _MIN_WORD_LENGTH:
                if current:
                    candidates.append(current[-_MAX_PHRASE_WORDS:])
                    current = []
                continue
            current.append(token)
        if current:
            candidates.append(current[-_MAX_PHRASE_WORDS:])

    return candidates


def _is_stopword(token: str) -> bool:
    return token in _STOPWORDS or fold_diacritics(token) in _STOPWORDS
