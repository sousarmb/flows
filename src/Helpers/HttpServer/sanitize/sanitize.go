package sanitize

import "unicode/utf8"

var invisibleRuneSet = map[rune]struct{}{
	// C0 control block
	'\u0000': {}, '\u0001': {}, '\u0002': {}, '\u0003': {}, '\u0004': {},
	'\u0005': {}, '\u0006': {}, '\u0007': {}, '\u0008': {}, '\u0009': {},
	'\u000A': {}, '\u000B': {}, '\u000C': {}, '\u000D': {}, '\u000E': {},
	'\u000F': {}, '\u0010': {}, '\u0011': {}, '\u0012': {}, '\u0013': {},
	'\u0014': {}, '\u0015': {}, '\u0016': {}, '\u0017': {}, '\u0018': {},
	'\u0019': {}, '\u001A': {}, '\u001B': {}, '\u001C': {}, '\u001D': {},
	'\u001E': {}, '\u001F': {},

	// Unicode spaces & invisibles
	'\u00A0': {}, // NO-BREAK SPACE
	'\u1680': {}, // OGHAM SPACE MARK
	'\u180E': {}, // MONGOLIAN VOWEL SEPARATOR

	'\u2000': {}, '\u2001': {}, '\u2002': {}, '\u2003': {}, '\u2004': {},
	'\u2005': {}, '\u2006': {}, '\u2007': {}, '\u2008': {}, '\u2009': {},
	'\u200A': {},

	// Zero-width
	'\u200B': {}, '\u200C': {}, '\u200D': {},
	'\u2060': {},

	// BiDi Trojan Source characters
	'\u200E': {}, '\u200F': {},
	'\u202A': {}, '\u202B': {}, '\u202C': {}, '\u202D': {}, '\u202E': {},

	// Invisible operators
	'\u2061': {}, '\u2062': {}, '\u2063': {}, '\u2064': {},

	// BOM / Zero-width NBSP
	'\uFEFF': {},
}

func StripInvisibleRunes(s string) string {
	if s == "" {
		return s
	}

	var out []rune
	for _, r := range s {
		if _, blocked := invisibleRuneSet[r]; !blocked {
			out = append(out, r)
		}
	}
	return string(out)
}

func StripInvisibleBytes(b []byte) []byte {
	if len(b) == 0 {
		return b
	}

	out := make([]byte, 0, len(b))
	for len(b) > 0 {
		r, size := utf8.DecodeRune(b)
		if r == utf8.RuneError && size == 1 {
			// Invalid UTF-8 byte – keep it so we don't corrupt binary data
			out = append(out, b[0])
			b = b[1:]
			continue
		}

		if _, blocked := invisibleRuneSet[r]; !blocked {
			out = append(out, b[:size]...)
		}
		b = b[size:]
	}
	return out
}
