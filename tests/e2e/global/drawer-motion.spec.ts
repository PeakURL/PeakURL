import { test, expect, type Page } from "@playwright/test";
import { drawerScenarios, mockDrawerPages } from "../fixtures/drawer.fixture";

/** Inspect the browser's running translation without depending on frame delivery. */
async function recordDrawerMotion(
	page: Page,
	trigger: string,
	panel: string,
	phase: "enter" | "leave"
) {
	await expect(page.locator(trigger).first()).toBeEnabled();
	return page
		.locator(trigger)
		.first()
		.evaluate(
			(button, { panel, phase }) =>
				new Promise<{
					progress: number[];
					easing: string;
					duration: string;
				}>((resolve, reject) => {
					if (!(button instanceof HTMLButtonElement)) {
						throw new Error("Drawer control must be a button");
					}
					const controller = new AbortController();
					const timeout = window.setTimeout(() => {
						controller.abort();
						reject(
							new Error(
								`Drawer did not start its ${phase} transition: ${panel}`
							)
						);
					}, 3000);

					document.addEventListener(
						"transitionrun",
						(event) => {
							const element = event.target;
							if (
								!(element instanceof HTMLElement) ||
								!element.matches(panel) ||
								!element.hasAttribute(`data-${phase}`) ||
								event.propertyName !== "translate"
							)
								return;
							const animation = element
								.getAnimations()
								.find(
									(candidate) =>
										candidate instanceof CSSTransition &&
										candidate.transitionProperty ===
											"translate"
								);
							if (!animation?.effect) return;

							controller.abort();
							window.clearTimeout(timeout);
							const style = getComputedStyle(element);
							const easing = style.transitionTimingFunction;
							const duration = style.transitionDuration;
							const milliseconds = Number(
								animation.effect.getTiming().duration
							);
							const { width } = element.getBoundingClientRect();
							const inset = parseFloat(style.marginInlineEnd);
							const finalX =
								style.direction === "rtl"
									? inset
									: innerWidth - width - inset;
							animation.pause();
							const progress = [0, 0.25, 0.5, 0.75, 0.99].map(
								(fraction) => {
									animation.currentTime =
										milliseconds * fraction;
									return (
										1 -
										Math.abs(
											element.getBoundingClientRect().x -
												finalX
										) /
											(width + inset)
									);
								}
							);
							animation.finish();
							resolve({ progress, easing, duration });
						},
						{ signal: controller.signal, capture: true }
					);
					button.click();
				}),
			{ panel, phase }
		);
}

for (const direction of ["ltr", "rtl"] as const) {
	test(`every drawer visibly slides on first opening and closing in ${direction}`, async ({
		page,
	}) => {
		await page.setViewportSize({ width: 1440, height: 900 });
		await mockDrawerPages(page, direction);
		for (const drawer of drawerScenarios) {
			await test.step(drawer.name, async () => {
				await page.goto(drawer.path);
				await expect(
					page.locator(drawer.trigger).first()
				).toBeVisible();
				for (const phase of ["enter", "leave"] as const) {
					const trigger =
						phase === "enter"
							? drawer.trigger
							: `${drawer.panel} ${drawer.close}`;
					const { progress, easing, duration } =
						await recordDrawerMotion(
							page,
							trigger,
							drawer.panel,
							phase
						);
					expect(
						progress.length,
						`${drawer.name} ${phase} must animate`
					).toBeGreaterThan(3);
					expect(
						progress.some(
							(position) => position > 0.1 && position < 0.9
						),
						`${drawer.name} ${phase} must pass through an intermediate position: ${progress.join(", ")}`
					).toBe(true);
					const first = progress[0];
					const last = progress.at(-1);
					expect(easing).toBe(
						phase === "enter"
							? "cubic-bezier(0.2, 0.75, 0.25, 1)"
							: "cubic-bezier(0.4, 0, 1, 1)"
					);
					expect(duration).toBe(phase === "enter" ? "0.3s" : "0.22s");
					if (phase === "enter") {
						await expect(
							page.locator(drawer.panel)
						).not.toHaveAttribute("data-enter");
						expect(first).toBeLessThan(0.25);
						expect(last).toBeGreaterThan(0.9);
					} else {
						expect(first).toBeGreaterThan(0.75);
						expect(last).toBeLessThan(0.1);
					}
				}
				await expect(page.locator(drawer.panel)).toHaveCount(0);
			});
		}
	});
}
