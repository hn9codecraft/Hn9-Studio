export default function AlertMessage({ variant = 'danger', children }) {
  if (!children) {
    return null;
  }

  return (
    <div className={`alert alert-${variant} mb-0`} role="alert">
      {children}
    </div>
  );
}
