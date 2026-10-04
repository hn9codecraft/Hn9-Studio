import { Link } from 'react-router-dom';
import colorLogo from '../../assets/brand/hn9-studio-color.png';
import whiteLogo from '../../assets/brand/hn9-studio-white.png';
import { useTheme } from '../../contexts/ThemeContext';

export const BRAND_NAME = 'HN9 Studio';

/**
 * The approved HN9 Studio artwork, one file per variant.
 * color → light backgrounds, white → dark backgrounds, icon → small spaces.
 * The icon is the same file the browser uses as the favicon.
 */
const LOGOS = {
  color: { src: colorLogo, width: 450, height: 179 },
  white: { src: whiteLogo, width: 450, height: 179 },
  icon: { src: `${import.meta.env.BASE_URL}favicon.png`, width: 70, height: 68 },
};

/**
 * `variant="auto"` follows the theme: color logo in light mode, white logo in dark mode.
 * Use it on surfaces that change with the theme. Use a fixed variant on surfaces that do not.
 */
export default function BrandLogo({ variant = 'auto', to = '/dashboard', className = '', height = 40 }) {
  const { isDark } = useTheme();
  const resolved = variant === 'auto' ? (isDark ? 'white' : 'color') : variant;
  const logo = LOGOS[resolved] || LOGOS.color;
  const width = Math.round((logo.width / logo.height) * height);

  const image = (
    <img
      className={`brand-logo brand-logo--${resolved}`}
      src={logo.src}
      alt={BRAND_NAME}
      width={width}
      height={height}
      decoding="async"
    />
  );

  if (!to) {
    return <span className={`brand-logo-wrap ${className}`.trim()}>{image}</span>;
  }

  return (
    <Link className={`brand-logo-wrap ${className}`.trim()} to={to} aria-label={`${BRAND_NAME} home`}>
      {image}
    </Link>
  );
}
