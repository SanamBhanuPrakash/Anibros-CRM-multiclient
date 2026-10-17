import { createTheme, type Shadows } from '@mui/material/styles';

/**
 * Anibros Design: design system v3.
 *
 * Direction: a working tool, not a brochure. Light and quiet by default, compact and scannable, one accent colour, flat white
 * surfaces on a very light grey page, thin borders instead of shadows, one typeface. Colour is reserved for meaning (status, client
 * identity, the single primary action). Dark mode is a first-class twin, not an afterthought.
 *
 * Font: Geist (self-hosted through Fontsource: no request to Google at runtime, works under a strict CSP and offline), with tabular
 * figures for every number. Geist Mono for ids and keys.
 */
export const fontStacks = {
  sans: '"Geist Variable", "Geist", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
  // One typeface for everything. Kept as a name so older call sites keep compiling; it is the same sans.
  serif: '"Geist Variable", "Geist", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
  mono: '"Geist Mono Variable", "Geist Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
};

declare module '@mui/material/styles' {
  interface Palette {
    surface: { sunken: string; raised: string; hover: string; selected: string };
    accentTone: { bg: string; fg: string; border: string };
    neutralTone: { bg: string; fg: string };
    /** Data-visualisation colours. Categorical series s1..s6 are distinguishable from one another and reach 3:1 against the surface in both themes. */
    chart: { s1: string; s2: string; s3: string; s4: string; s5: string; s6: string; muted: string; grid: string };
  }
  interface PaletteOptions {
    surface?: { sunken: string; raised: string; hover: string; selected: string };
    accentTone?: { bg: string; fg: string; border: string };
    neutralTone?: { bg: string; fg: string };
    chart?: { s1: string; s2: string; s3: string; s4: string; s5: string; s6: string; muted: string; grid: string };
  }
  interface TypographyVariants {
    display: React.CSSProperties;
    numeric: React.CSSProperties;
    mono: React.CSSProperties;
    eyebrow: React.CSSProperties;
  }
  interface TypographyVariantsOptions {
    display?: React.CSSProperties;
    numeric?: React.CSSProperties;
    mono?: React.CSSProperties;
    eyebrow?: React.CSSProperties;
  }
}
declare module '@mui/material/Typography' {
  interface TypographyPropsVariantOverrides {
    display: true;
    numeric: true;
    mono: true;
    eyebrow: true;
  }
}

const EASE = 'cubic-bezier(0.2, 0.8, 0.2, 1)';

/**
 * Colour access inside component overrides MUST go through the CSS-variable palette (`theme.vars`), never `theme.palette`:
 * the latter is the default (light) scheme only, so anything read from it would not follow dark mode.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const P = (t: any) => t.vars.palette;
/** rgba() from a palette colour channel variable: ch(t,'primary',0.16) */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const ch = (t: any, color: string, a: number) => `rgba(${P(t)[color].mainChannel} / ${a})`;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const chText = (t: any, a: number) => `rgba(${P(t).text.primaryChannel} / ${a})`;

// Neutral, short shadows: only floating things (menus, dialogs) get one. Everything else is flat with a 1px border.
const tint = '16, 24, 40';
const shadows: Shadows = [
  'none',
  `0 1px 2px rgba(${tint}, 0.05)`,
  `0 1px 3px rgba(${tint}, 0.08), 0 1px 2px rgba(${tint}, 0.04)`,
  `0 2px 6px rgba(${tint}, 0.08), 0 1px 2px rgba(${tint}, 0.04)`,
  `0 4px 10px -2px rgba(${tint}, 0.10), 0 2px 4px rgba(${tint}, 0.04)`,
  `0 8px 20px -4px rgba(${tint}, 0.12), 0 2px 6px rgba(${tint}, 0.05)`,
  `0 12px 28px -6px rgba(${tint}, 0.14), 0 2px 6px rgba(${tint}, 0.05)`,
  `0 16px 36px -8px rgba(${tint}, 0.16), 0 4px 8px rgba(${tint}, 0.06)`,
  ...Array(17).fill(`0 20px 44px -10px rgba(${tint}, 0.20), 0 6px 12px rgba(${tint}, 0.07)`),
] as Shadows;

export const theme = createTheme({
  cssVariables: { colorSchemeSelector: 'data-color-scheme', cssVarPrefix: 'a' },
  defaultColorScheme: 'light',
  colorSchemes: {
    light: {
      palette: {
        mode: 'light',
        primary: { main: '#4F46E5', light: '#6366F1', dark: '#4338CA', contrastText: '#FFFFFF' },
        secondary: { main: '#374151', light: '#4B5563', dark: '#1F2937', contrastText: '#FFFFFF' },
        success: { main: '#168A5A', light: '#34A777', dark: '#0F6A44', contrastText: '#FFFFFF' },
        warning: { main: '#B45309', light: '#D97706', dark: '#92400E', contrastText: '#FFFFFF' },
        error: { main: '#D92D20', light: '#EF5B50', dark: '#B42318', contrastText: '#FFFFFF' },
        info: { main: '#2563EB', light: '#4F86F0', dark: '#1D4FBF', contrastText: '#FFFFFF' },
        background: { default: '#F4F4F1', paper: '#FFFFFF' },
        text: { primary: '#111827', secondary: '#5B6475', disabled: '#7C8595' },
        divider: '#E5E7EB',
        surface: { sunken: '#F1F3F6', raised: '#FFFFFF', hover: 'rgba(17,24,39,0.04)', selected: 'rgba(79,70,229,0.08)' },
        accentTone: { bg: '#EEF2FF', fg: '#4338CA', border: '#C7D2FE' },
        neutralTone: { bg: '#F1F3F6', fg: '#374151' },
        chart: { s1: '#4F46E5', s2: '#B45309', s3: '#0369A1', s4: '#DC2626', s5: '#7C3AED', s6: '#0F766E', muted: '#D1D5DB', grid: '#E5E7EB' },
      },
    },
    dark: {
      palette: {
        mode: 'dark',
        primary: { main: '#8B93FF', light: '#A5ABFF', dark: '#6C74F0', contrastText: '#0B0D2E' },
        secondary: { main: '#CBD2DD', light: '#E1E5EC', dark: '#A3ACBB', contrastText: '#12141A' },
        success: { main: '#4CC38A', light: '#74D6A6', dark: '#2FA06C', contrastText: '#05180F' },
        warning: { main: '#F0A64A', light: '#F6BE78', dark: '#C98424', contrastText: '#241503' },
        error: { main: '#F0746B', light: '#F59A93', dark: '#D1544B', contrastText: '#2A0A07' },
        info: { main: '#6FA3FF', light: '#96BDFF', dark: '#4C84E8', contrastText: '#06162E' },
        background: { default: '#0E1014', paper: '#15181E' },
        text: { primary: '#E6E8EC', secondary: '#9AA3B2', disabled: '#6F7889' },
        divider: '#262B34',
        surface: { sunken: '#0B0D11', raised: '#1B1F27', hover: 'rgba(255,255,255,0.055)', selected: 'rgba(139,147,255,0.16)' },
        accentTone: { bg: '#1E2140', fg: '#B3B8FF', border: '#2E3366' },
        neutralTone: { bg: '#20242C', fg: '#CBD2DD' },
        chart: { s1: '#8B93FF', s2: '#F0A64A', s3: '#6FB6E8', s4: '#F0746B', s5: '#B79BFF', s6: '#5CC8B8', muted: '#3A404C', grid: '#262B34' },
      },
    },
  },
  shape: { borderRadius: 8 },
  shadows,
  breakpoints: { values: { xs: 0, sm: 600, md: 900, lg: 1200, xl: 1536 } },
  typography: {
    fontFamily: fontStacks.sans,
    fontSize: 14,
    h1: { fontWeight: 650, fontSize: '1.75rem', lineHeight: 1.2, letterSpacing: '-0.025em' },
    h2: { fontWeight: 600, fontSize: '1.25rem', lineHeight: 1.25, letterSpacing: '-0.014em' },
    h3: { fontWeight: 600, fontSize: '1.125rem', lineHeight: 1.3, letterSpacing: '-0.01em' },
    h4: { fontWeight: 600, fontSize: '1rem', lineHeight: 1.35, letterSpacing: '-0.006em' },
    h5: { fontWeight: 600, fontSize: '0.875rem', lineHeight: 1.4 },
    h6: { fontWeight: 600, fontSize: '0.8125rem', lineHeight: 1.4 },
    subtitle1: { fontWeight: 600, fontSize: '0.9375rem', lineHeight: 1.4 },
    subtitle2: { fontWeight: 600, fontSize: '0.8125rem', lineHeight: 1.4 },
    body1: { fontSize: '0.875rem', lineHeight: 1.5 },
    body2: { fontSize: '0.8125rem', lineHeight: 1.45 },
    button: { textTransform: 'none', fontWeight: 500, fontSize: '0.8125rem', letterSpacing: 0 },
    caption: { fontSize: '0.75rem', lineHeight: 1.4 },
    overline: { fontSize: '0.6875rem', fontWeight: 600, letterSpacing: '0.06em', textTransform: 'uppercase', lineHeight: 1.4 },
    display: { fontWeight: 600, fontSize: '1.75rem', lineHeight: 1.1, letterSpacing: '-0.02em', fontVariantNumeric: 'tabular-nums' },
    numeric: { fontVariantNumeric: 'tabular-nums', fontFeatureSettings: '"tnum"', letterSpacing: '-0.005em' },
    mono: { fontFamily: fontStacks.mono, fontSize: '0.8125rem', letterSpacing: 0 },
    eyebrow: { fontSize: '0.6875rem', fontWeight: 600, letterSpacing: '0.06em', textTransform: 'uppercase', lineHeight: 1.4 },
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: (t) => ({
        html: { WebkitFontSmoothing: 'antialiased', MozOsxFontSmoothing: 'grayscale', textRendering: 'optimizeLegibility' },
        body: { fontVariantNumeric: 'tabular-nums' },
        '::selection': { backgroundColor: ch(t, 'primary', 0.22) },
        'code, kbd, pre, samp': { fontFamily: fontStacks.mono },
        // Slim, quiet scrollbars (tables and tab strips scroll sideways on small screens).
        '*': { scrollbarWidth: 'thin', scrollbarColor: `${chText(t, 0.22)} transparent` },
        // Honour reduced-motion preferences globally.
        '@media (prefers-reduced-motion: reduce)': {
          '*, *::before, *::after': { animationDuration: '0.01ms !important', animationIterationCount: '1 !important', transitionDuration: '0.01ms !important', scrollBehavior: 'auto !important' },
        },
        '*:focus-visible': { outline: `2px solid ${P(t).primary.main}`, outlineOffset: 1, borderRadius: 4 },
        '#root': { minHeight: '100dvh' },
      }),
    },
    MuiButtonBase: { defaultProps: { disableRipple: true } },
    MuiButton: {
      defaultProps: { disableElevation: true, size: 'medium' },
      styleOverrides: {
        root: ({ theme: t, ownerState }) => ({
          borderRadius: 8,
          paddingInline: 12,
          minHeight: 34,
          whiteSpace: 'nowrap',
          transition: `background-color 120ms ${EASE}, border-color 120ms ${EASE}, color 120ms ${EASE}`,
          '&.Mui-focusVisible': { outline: `2px solid ${P(t).primary.main}`, outlineOffset: 1 },
          ...(ownerState.variant === 'contained' && ownerState.color === 'primary' ? { '&:hover': { backgroundColor: P(t).primary.dark } } : {}),
        }),
        sizeSmall: { paddingInline: 10, minHeight: 28, fontSize: '0.8125rem' },
        sizeLarge: { paddingInline: 18, minHeight: 40, fontSize: '0.875rem' },
        outlined: ({ theme: t }) => ({ borderColor: P(t).divider, color: P(t).text.primary, backgroundColor: P(t).background.paper, '&:hover': { borderColor: P(t).text.disabled, backgroundColor: P(t).surface.hover } }),
        text: ({ theme: t }) => ({ '&:hover': { backgroundColor: P(t).surface.hover } }),
      },
    },
    MuiIconButton: {
      styleOverrides: { root: ({ theme: t }) => ({ borderRadius: 8, transition: `background-color 120ms ${EASE}`, '&:hover': { backgroundColor: P(t).surface.hover } }) },
    },
    MuiToggleButtonGroup: { styleOverrides: { root: ({ theme: t }) => ({ backgroundColor: P(t).background.paper, borderRadius: 8 }) } },
    MuiToggleButton: {
      styleOverrides: {
        root: ({ theme: t }) => ({
          textTransform: 'none',
          fontWeight: 500,
          borderColor: P(t).divider,
          color: P(t).text.secondary,
          paddingBlock: 5,
          paddingInline: 12,
          '&.Mui-selected': { backgroundColor: P(t).surface.selected, color: P(t).primary.main, borderColor: P(t).divider, '&:hover': { backgroundColor: P(t).surface.selected } },
        }),
      },
    },
    MuiTextField: { defaultProps: { size: 'small', variant: 'outlined', fullWidth: true } },
    MuiFormControl: { defaultProps: { size: 'small' } },
    MuiInputLabel: { styleOverrides: { root: ({ theme: t }) => ({ fontSize: '0.8125rem', color: P(t).text.secondary }) } },
    MuiOutlinedInput: {
      styleOverrides: {
        root: ({ theme: t }) => ({
          borderRadius: 8,
          fontSize: '0.875rem',
          backgroundColor: P(t).background.paper,
          '& .MuiOutlinedInput-notchedOutline': { borderColor: P(t).divider },
          '&:hover .MuiOutlinedInput-notchedOutline': { borderColor: P(t).text.disabled },
          '&.Mui-focused': { boxShadow: `0 0 0 3px ${ch(t, 'primary', 0.14)}` },
          '&.Mui-focused .MuiOutlinedInput-notchedOutline': { borderColor: P(t).primary.main, borderWidth: 1 },
          '&.Mui-error.Mui-focused': { boxShadow: `0 0 0 3px ${ch(t, 'error', 0.14)}` },
        }),
        input: { paddingBlock: 7.5, paddingInline: 11 },
      },
    },
    MuiFormHelperText: { styleOverrides: { root: { marginInline: 2, fontSize: '0.75rem' } } },
    // Cards are lifted by a hairline ring and a whisper of shadow, never a hard border: softer on a tinted page, still crisp in dark mode.
    MuiPaper: {
      defaultProps: { elevation: 0 },
      styleOverrides: {
        root: { backgroundImage: 'none' },
        outlined: ({ theme: t }) => ({ border: 'none', borderRadius: 14, boxShadow: `0 0 0 1px ${chText(t, 0.07)}, 0 1px 2px ${chText(t, 0.04)}` }),
      },
    },
    MuiCard: {
      defaultProps: { elevation: 0 },
      styleOverrides: { root: ({ theme: t }) => ({ borderRadius: 10, border: `1px solid ${P(t).divider}`, backgroundColor: P(t).background.paper }) },
    },
    MuiChip: {
      styleOverrides: {
        root: { borderRadius: 6, fontWeight: 500, height: 24, fontSize: '0.75rem' },
        sizeSmall: { height: 22 },
        label: { paddingInline: 8 },
      },
    },
    MuiAvatar: { styleOverrides: { root: { borderRadius: '50%', fontSize: '0.75rem', fontWeight: 600 } } },
    MuiTooltip: {
      defaultProps: { arrow: false, enterDelay: 350, enterNextDelay: 80 },
      styleOverrides: {
        tooltip: ({ theme: t }) => ({
          backgroundColor: '#111827',
          color: '#F9FAFB',
          fontSize: '0.75rem',
          fontWeight: 500,
          borderRadius: 6,
          paddingBlock: 4,
          paddingInline: 8,
          ...t.applyStyles('dark', { backgroundColor: '#E6E8EC', color: '#111827' }),
        }),
      },
    },
    MuiDialog: {
      defaultProps: { fullWidth: true, maxWidth: 'sm' },
      styleOverrides: { paper: ({ theme: t }) => ({ borderRadius: 12, border: `1px solid ${P(t).divider}`, boxShadow: t.shadows[7] }) },
    },
    MuiDialogTitle: { styleOverrides: { root: { fontWeight: 600, fontSize: '1.0625rem', letterSpacing: '-0.01em', paddingBottom: 6 } } },
    // MUI removes the top padding after a title, which clips the floating label of the first field.
    MuiDialogContent: { styleOverrides: { root: { '.MuiDialogTitle-root + &': { paddingTop: 10 } } } },
    MuiDialogActions: { styleOverrides: { root: { padding: '10px 24px 18px', gap: 4 } } },
    MuiDrawer: { styleOverrides: { paper: ({ theme: t }) => ({ backgroundImage: 'none', borderColor: P(t).divider }) } },
    MuiMenu: { styleOverrides: { paper: ({ theme: t }) => ({ borderRadius: 10, border: `1px solid ${P(t).divider}`, boxShadow: t.shadows[5], marginTop: 4 }) } },
    MuiPopover: { styleOverrides: { paper: ({ theme: t }) => ({ borderRadius: 10, border: `1px solid ${P(t).divider}`, boxShadow: t.shadows[5] }) } },
    MuiMenuItem: { styleOverrides: { root: ({ theme: t }) => ({ borderRadius: 6, marginInline: 4, fontSize: '0.8125rem', minHeight: 34, '&.Mui-selected': { backgroundColor: P(t).surface.selected } }) } },
    MuiListItemButton: {
      styleOverrides: {
        root: ({ theme: t }) => ({
          borderRadius: 8,
          transition: `background-color 120ms ${EASE}`,
          '&:hover': { backgroundColor: P(t).surface.hover },
          '&.Mui-selected': { backgroundColor: P(t).surface.selected, '&:hover': { backgroundColor: P(t).surface.selected } },
        }),
      },
    },
    MuiTabs: { styleOverrides: { indicator: { height: 2, borderRadius: 2 }, root: ({ theme: t }) => ({ minHeight: 38, borderBottom: `1px solid ${P(t).divider}` }) } },
    MuiTab: { styleOverrides: { root: { minHeight: 38, textTransform: 'none', fontWeight: 500, fontSize: '0.8125rem', paddingInline: 12 } } },
    MuiTableCell: {
      styleOverrides: {
        root: ({ theme: t }) => ({ borderColor: P(t).divider, paddingBlock: 8, paddingInline: 12, fontSize: '0.8125rem', fontVariantNumeric: 'tabular-nums' }),
        head: ({ theme: t }) => ({ fontWeight: 600, fontSize: '0.6875rem', letterSpacing: '0.04em', textTransform: 'uppercase', color: P(t).text.secondary, backgroundColor: 'transparent', whiteSpace: 'nowrap' }),
      },
    },
    MuiTableRow: { styleOverrides: { root: ({ theme: t }) => ({ '&.MuiTableRow-hover:hover': { backgroundColor: P(t).surface.hover } }) } },
    MuiSkeleton: { defaultProps: { animation: 'wave' }, styleOverrides: { root: ({ theme: t }) => ({ backgroundColor: chText(t, 0.06), borderRadius: 6 }) } },
    MuiAlert: {
      styleOverrides: {
        root: ({ theme: t, ownerState }) => {
          const sev = ownerState.severity ?? 'info';
          return {
            borderRadius: 8,
            alignItems: 'flex-start',
            fontSize: '0.8125rem',
            ...(ownerState.variant === 'standard' ? { backgroundColor: ch(t, sev, 0.08), color: P(t).text.primary, '& .MuiAlert-icon': { color: P(t)[sev].main } } : {}),
          };
        },
      },
    },
    MuiSnackbarContent: { styleOverrides: { root: { borderRadius: 8 } } },
    MuiSwitch: { defaultProps: { size: 'small' } },
    MuiLinearProgress: { styleOverrides: { root: { borderRadius: 4, height: 4 } } },
    MuiDivider: { styleOverrides: { root: ({ theme: t }) => ({ borderColor: P(t).divider }) } },
    MuiLink: { defaultProps: { underline: 'hover' }, styleOverrides: { root: { fontWeight: 500 } } },
    MuiBreadcrumbs: { styleOverrides: { root: { fontSize: '0.8125rem' } } },
    MuiBadge: { styleOverrides: { badge: { fontWeight: 600, fontSize: '0.6875rem' } } },
  },
});

export type AppTheme = typeof theme;
