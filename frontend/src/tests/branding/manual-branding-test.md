# Manual Branding Test Checklist

This document provides a checklist for manually testing the Eventforce branding across key UI components.

## Test Environment Setup

1. **Start the development server:**
   ```bash
   cd frontend
   npm run dev
   ```

2. **Verify environment configuration:**
   - Check that `frontend/.env` contains:
     - `VITE_APP_NAME=Eventforce`
     - `VITE_APP_LOGO_DARK=/eventforce.svg`
     - `VITE_APP_LOGO_LIGHT=/eventforce.svg`

3. **Verify logo assets exist:**
   - Check `frontend/public/eventforce.svg` exists
   - Check `frontend/public/eventforce_logocolor@4x.png` exists

## Component Testing Checklist

### ✅ AuthLayout Logo Display
**Test URL:** `http://localhost:5173/auth/login`

- [ ] Logo displays correctly in authentication layout
- [ ] Logo uses `/eventforce.svg` source
- [ ] Alt text shows "Eventforce logo"
- [ ] Logo is properly sized and positioned
- [ ] Logo is responsive on mobile devices

**Expected behavior:**
- Logo should appear in the left panel of the auth layout
- Should use the dark logo configuration (`VITE_APP_LOGO_DARK`)

### ✅ Sidebar Logo Display  
**Test URL:** `http://localhost:5173/manage/events` (requires login)

- [ ] Logo displays correctly in main application sidebar
- [ ] Logo uses `/eventforce.svg` source
- [ ] Alt text shows "Eventforce logo"
- [ ] Logo has proper styling (max-width: 160px, margin: 10px auto)
- [ ] Logo is clickable and navigates to `/manage/events`
- [ ] Logo is responsive when sidebar is collapsed/expanded

**Expected behavior:**
- Logo should appear at the top of the sidebar
- Should use the light logo configuration (`VITE_APP_LOGO_LIGHT`)

### ✅ ErrorDisplay Logo Display
**Test URL:** Navigate to a non-existent page like `http://localhost:5173/nonexistent`

- [ ] Logo displays correctly on 404 error page
- [ ] Logo uses `/eventforce.svg` source  
- [ ] Alt text shows "Eventforce Logo"
- [ ] Logo is properly sized (width: 140px)
- [ ] Logo is centered on the page

**Expected behavior:**
- Logo should appear above the error message
- Should use the dark logo configuration (`VITE_APP_LOGO_DARK`)

### ✅ GenericErrorPage Logo Display
**Test URL:** Trigger a generic error or use error pages in the app

- [ ] Logo displays correctly on generic error pages
- [ ] Logo uses `/eventforce.svg` source
- [ ] Alt text shows "Eventforce Logo"  
- [ ] Logo is properly sized (width: 140px)
- [ ] Logo is centered on the page

**Expected behavior:**
- Logo should appear above the error title and description
- Should use the dark logo configuration (`VITE_APP_LOGO_DARK`)

## Responsive Testing

### Desktop (1024px+)
- [ ] All logos display at full size
- [ ] Sidebar logo maintains proper proportions
- [ ] Auth layout logo is properly positioned

### Tablet (768px - 1023px)
- [ ] Logos scale appropriately
- [ ] Sidebar behavior is responsive
- [ ] Auth layout adapts correctly

### Mobile (< 768px)
- [ ] Logos remain visible and properly sized
- [ ] Sidebar collapses appropriately
- [ ] Auth layout stacks correctly

## Logo Fallback Testing

### Missing Logo Files
1. Temporarily rename `eventforce.svg` to test fallback
2. Verify graceful degradation:
   - [ ] No broken image icons appear
   - [ ] Alt text is displayed as fallback
   - [ ] Layout remains intact

### Network Issues
1. Use browser dev tools to simulate slow network
2. Verify:
   - [ ] Logo loading states are handled gracefully
   - [ ] No layout shifts occur during loading

## Browser Compatibility

Test in the following browsers:
- [ ] Chrome (latest)
- [ ] Firefox (latest)  
- [ ] Safari (latest)
- [ ] Edge (latest)

Verify:
- [ ] SVG logos render correctly in all browsers
- [ ] No console errors related to logo loading
- [ ] Consistent appearance across browsers

## Performance Testing

- [ ] Logo files load quickly (< 1s)
- [ ] No unnecessary logo requests in network tab
- [ ] SVG logos are properly optimized
- [ ] No layout shifts during logo loading

## Accessibility Testing

- [ ] Logo alt text is descriptive and accurate
- [ ] Logo images have proper contrast ratios
- [ ] Logo links are keyboard accessible
- [ ] Screen readers announce logo correctly

## Test Results

**Date:** ___________
**Tester:** ___________
**Browser:** ___________
**Screen Size:** ___________

### Issues Found:
- [ ] No issues found
- [ ] Issues found (list below):

### Notes: