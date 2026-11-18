import { describe, expect, it } from 'vitest'
import { personLine, pickedLabel } from '../../src/filters/fields'
import type { Principal } from '../../src/types/Search'

const t = (text: string) => text
const person = (id: string, displayName: string, subname = '', status?: Principal['status']): Principal =>
	({ type: 'user', id, displayName, subname, status })

const people = [person('demo', 'Demo', 'demo'), person('d15.demo', 'Demo', 'd15.demo'), person('a3bf86', 'Anna', 'a3bf86')]

describe('the line under a person', () => {
	it('tells people of the same name apart', () => {
		expect(personLine(t, people[1], people)).toBe('d15.demo')
		expect(pickedLabel(t, people[1], people)).toBe('Demo (d15.demo)')
	})

	it('falls back on the id where the server gave no second line', () => {
		const namesakes = [person('one', 'Demo'), person('two', 'Demo')]
		expect(personLine(t, namesakes[1], namesakes)).toBe('two')
	})

	it('leaves a unique name alone, so no hashed id is shown', () => {
		expect(personLine(t, people[2], people)).toBe('')
		expect(pickedLabel(t, people[2], people)).toBe('Anna')
	})

	it('says when someone has left', () => {
		const gone = person('jdv', 'jdv', '', 'deleted')
		expect(pickedLabel(t, gone, [gone])).toBe('jdv (Deleted account)')
		const off = person('jan', 'Jan', 'jan', 'disabled')
		expect(personLine(t, off, [off, person('j2', 'Jan', 'j2')])).toBe('Disabled account · jan')
	})
})
